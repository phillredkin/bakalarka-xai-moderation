from fastapi import FastAPI, UploadFile, File, HTTPException
import whisper
import tempfile
import os
import time

app = FastAPI(title="Audio Transcription Service (Whisper)")

model = whisper.load_model("base")

@app.get("/health")
def health():
    return {
        "status": "ok", "service": "audio-service"
    }

@app.post("/transcribe")
async def transcribe(file: UploadFile = File(...)):
    start = time.time()

    if not file:
        raise HTTPException(status_code=400, detail="No file provided")

    allowed_extensions = [".mp3", ".wav"]

    ext = os.path.splitext(file.filename or "")[1].lower()

    if ext not in allowed_extensions:
        raise HTTPException(status_code=400, detail=f"Unsupported file type: {ext}")

    suffix = os.path.splitext(file.filename or "")[1] or ".tmp"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        tmp_path = tmp.name
        content = await file.read()
        tmp.write(content)

    try:
        result = model.transcribe(tmp_path)
        text = (result.get("text") or "").strip()
    finally:
        try:
            os.remove(tmp_path)
        except:
            pass

    return {
        "service": "audio-service",
        "status": "ok",
        "text": text,
        "latency_ms": int((time.time() - start) * 1000),
        "filename": file.filename,
        "content_type": file.content_type
    }
