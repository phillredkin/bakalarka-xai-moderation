from flask import Flask, request, jsonify
from moviepy.editor import VideoFileClip
from concurrent.futures import ThreadPoolExecutor
import requests
import os
import uuid
import cv2
import re
from difflib import SequenceMatcher
import pytesseract

app = Flask(__name__)

UPLOAD_DIR = "/tmp/video_uploads"
AUDIO_SERVICE_URL = os.getenv("AUDIO_SERVICE_URL", "http://audio-service:8000/transcribe")
TEXT_SERVICE_URL = os.getenv("TEXT_SERVICE_URL", "http://text-service:8000/analyze")
SIGHTENGINE_API_USER = os.getenv("SIGHTENGINE_API_USER")
SIGHTENGINE_API_KEY = os.getenv("SIGHTENGINE_API_KEY")

OCR_FRAME_INTERVAL_SEC = float(os.getenv("OCR_FRAME_INTERVAL_SEC", "1"))
OCR_MAX_UNIQUE_TEXTS = int(os.getenv("OCR_MAX_UNIQUE_TEXTS", "20"))
OCR_SIMILARITY_THRESHOLD = float(os.getenv("OCR_SIMILARITY_THRESHOLD", "0.90"))

os.makedirs(UPLOAD_DIR, exist_ok=True)


def convert_to_mp3(video_path):
    if not os.path.exists(video_path):
        raise Exception(f"File not found: {video_path}")

    if not video_path.lower().endswith(".mp4"):
        raise Exception("Only .mp4 files are allowed")

    base_name = os.path.splitext(video_path)[0]
    audio_path = base_name + ".mp3"

    clip = VideoFileClip(video_path)
    try:
        if clip.audio is None:
            raise Exception("Video has no audio track")

        clip.audio.write_audiofile(audio_path, codec="mp3", logger=None)
    finally:
        clip.close()

    return audio_path


def normalize_ocr_text(text: str) -> str:
    if text is None:
        return ""

    text = text.strip().lower()
    text = text.replace("\n", " ")
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def contains_letters(text: str) -> bool:
    if not text:
        return False

    return re.search(r"[a-zA-Z]", text) is not None


def is_domain_like_text(text: str) -> bool:
    if not text:
        return False

    value = text.strip().lower()
    return bool(re.fullmatch(r"[a-z0-9.-]+[:.]?(com|net|org|io|gg|tv|ru|ua|sk|cz|de)", value))


def has_enough_letters(text: str, min_letters: int = 3) -> bool:
    if not text:
        return False

    letters = re.findall(r"[a-zA-Z]", text)
    return len(letters) >= min_letters


def is_meaningful_text(text: str) -> bool:
    if not text:
        return False

    stripped = text.strip()
    if not stripped:
        return False

    if not contains_letters(stripped):
        return False

    if not has_enough_letters(stripped, 3):
        return False

    if is_domain_like_text(stripped):
        return False

    return True


def is_similar(a: str, b: str, threshold: float = OCR_SIMILARITY_THRESHOLD) -> bool:
    return SequenceMatcher(None, a, b).ratio() >= threshold


def is_duplicate_text(text: str, existing_items: list) -> bool:
    for item in existing_items:
        existing_text = item["normalized_text"]
        if text == existing_text:
            return True
        if is_similar(text, existing_text):
            return True
    return False


def run_ocr_on_frame(frame):
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    text = pytesseract.image_to_string(gray, lang="eng")
    return text


def extract_unique_ocr_texts(video_path, interval_sec=OCR_FRAME_INTERVAL_SEC, max_unique=OCR_MAX_UNIQUE_TEXTS):
    if not os.path.exists(video_path):
        raise Exception("Video file not found for OCR")

    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        raise Exception("Could not open video for OCR")

    fps = cap.get(cv2.CAP_PROP_FPS)
    if not fps or fps <= 0:
        fps = 25.0

    frame_interval = max(1, int(fps * interval_sec))
    frame_index = 0
    unique_items = []
    frames_checked = 0

    try:
        while True:
            ret, frame = cap.read()
            if not ret:
                break

            if frame_index % frame_interval == 0:
                frames_checked += 1

                raw_text = run_ocr_on_frame(frame).strip()
                normalized_text = normalize_ocr_text(raw_text)

                if not is_meaningful_text(normalized_text):
                    frame_index += 1
                    continue

                if is_duplicate_text(normalized_text, unique_items):
                    frame_index += 1
                    continue

                unique_items.append({
                    "frame": frame_index,
                    "time": round(frame_index / fps, 2),
                    "text": raw_text,
                    "normalized_text": normalized_text
                })

                if len(unique_items) >= max_unique:
                    break

            frame_index += 1

    finally:
        cap.release()

    return {
        "frames_checked": frames_checked,
        "unique_items": unique_items
    }


def moderate_text(text):
    response = requests.post(
        TEXT_SERVICE_URL,
        json={"text": text},
        timeout=60
    )

    if response.status_code != 200:
        return {
            "status": "error",
            "error": "text-service unavailable",
            "details": response.text
        }

    return response.json()


def process_audio_analysis(video_path):
    mp3_path = os.path.splitext(video_path)[0] + ".mp3"

    try:
        audio_data = {
            "status": "no_speech_detected",
            "text": ""
        }
        moderation_data = None

        mp3_path = convert_to_mp3(video_path)

        with open(mp3_path, "rb") as f:
            audio_response = requests.post(
                AUDIO_SERVICE_URL,
                files={"file": ("audio.mp3", f, "audio/mpeg")},
                timeout=180
            )

        if audio_response.status_code != 200:
            return {
                "audio": {
                    "status": "audio_service_error",
                    "text": "",
                    "details": audio_response.text
                },
                "moderation": None
            }

        audio_json = audio_response.json()
        transcript = (audio_json.get("text") or "").strip()

        if transcript:
            audio_data = audio_json

            moderation_response = requests.post(
                TEXT_SERVICE_URL,
                json={"text": transcript},
                timeout=60
            )

            if moderation_response.status_code == 200:
                moderation_data = moderation_response.json()
            else:
                moderation_data = {
                    "status": "error",
                    "error": "text-service unavailable",
                    "details": moderation_response.text
                }
        else:
            audio_data = audio_json
            audio_data["status"] = "no_speech_detected"
            audio_data["text"] = ""

        return {
            "audio": audio_data,
            "moderation": moderation_data
        }

    except Exception as e:
        return {
            "audio": {
                "status": "audio_processing_error",
                "text": "",
                "details": str(e)
            },
            "moderation": None
        }

    finally:
        try:
            if os.path.exists(mp3_path):
                os.remove(mp3_path)
        except Exception:
            pass


def process_video_ocr_analysis(video_path):
    try:
        ocr_data = extract_unique_ocr_texts(video_path)

        results = []
        for item in ocr_data["unique_items"]:
            moderation = moderate_text(item["normalized_text"])
            results.append({
                "time": item["time"],
                "text": item["text"],
                "normalized_text": item["normalized_text"],
                "moderation": moderation
            })

        return {
            "status": "ok",
            "frames_checked": ocr_data["frames_checked"],
            "unique_texts_count": len(results),
            "items": results
        }

    except Exception as e:
        return {
            "status": "error",
            "frames_checked": 0,
            "unique_texts_count": 0,
            "items": [],
            "details": str(e)
        }


def start_sightengine_video_check(video_path):
    if not SIGHTENGINE_API_USER or not SIGHTENGINE_API_KEY:
        raise Exception("Sightengine credentials are missing")

    with open(video_path, "rb") as vf:
        sight_response = requests.post(
            "https://api.sightengine.com/1.0/video/check.json",
            files={"media": vf},
            data={
                "models": "weapon,offensive,violence,gore-2.0,self-harm,recreational_drug"
            },
            params={
                "api_user": SIGHTENGINE_API_USER,
                "api_secret": SIGHTENGINE_API_KEY
            },
            timeout=120
        )

    if sight_response.status_code != 200:
        raise Exception(sight_response.text)

    return sight_response.json()


@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "ok",
        "service": "video-service"
    })


@app.route("/analyze", methods=["POST"])
def analyze():
    if "file" not in request.files:
        return jsonify({"error": "No video uploaded"}), 400

    file = request.files["file"]

    if not file.filename:
        return jsonify({"error": "Empty filename"}), 400

    if not SIGHTENGINE_API_USER or not SIGHTENGINE_API_KEY:
        return jsonify({"error": "Sightengine credentials are missing"}), 500

    unique_name = f"{uuid.uuid4().hex}.mp4"
    video_path = os.path.join(UPLOAD_DIR, unique_name)
    mp3_path = os.path.splitext(video_path)[0] + ".mp3"

    try:
        file.save(video_path)

        with ThreadPoolExecutor(max_workers=3) as executor:
            audio_future = executor.submit(process_audio_analysis, video_path)
            ocr_future = executor.submit(process_video_ocr_analysis, video_path)
            sight_future = executor.submit(start_sightengine_video_check, video_path)

            audio_analysis = audio_future.result()
            ocr_analysis = ocr_future.result()

            try:
                sight_data = sight_future.result()
            except Exception as sight_err:
                return jsonify({
                    "error": "sightengine unavailable",
                    "details": str(sight_err)
                }), 502

        return jsonify({
            "status": "ok",
            "service": "video-service",
            "audio_analysis": audio_analysis,
            "video_text_analysis": ocr_analysis,
            "video_moderation_async": sight_data
        })

    except Exception as e:
        return jsonify({
            "error": "Video analysis failed",
            "details": str(e)
        }), 500

    finally:
        try:
            if os.path.exists(video_path):
                os.remove(video_path)
        except Exception:
            pass

        try:
            if os.path.exists(mp3_path):
                os.remove(mp3_path)
        except Exception:
            pass


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000, debug=True)