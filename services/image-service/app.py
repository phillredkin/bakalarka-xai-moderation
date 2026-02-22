from fastapi import FastAPI, UploadFile, File, Request
import os
import requests

app = FastAPI()

OCR_KEY = os.getenv("OCR_SPACE_API_KEY")

@app.post("/analyze")
async def analyze(request: Request, file: UploadFile = File(None)):

    headers = {
        "apikey": OCR_KEY
    }

    if file:
        files = {
            "file": (file.filename, await file.read())
        }

        data = {
            "language": "eng",
            "OCREngine": 2
        }

        response = requests.post(
            "https://api.ocr.space/parse/image",
            headers=headers,
            files=files,
            data=data
        )

    else:
        body = await request.json()
        url = body.get("url")

        if not url:
            return {"error": "No URL provided"}

        data = {
            "url": url,
            "language": "eng",
            "OCREngine": 2
        }

        response = requests.post(
            "https://api.ocr.space/parse/image",
            headers=headers,
            data=data
        )

    if response.status_code != 200:
        return {
            "error": "OCR API error",
            "details": response.text
        }

    result = response.json()

    if result.get("IsErroredOnProcessing"):
        return {
            "error": "OCR failed",
            "details": result
        }

    parsed = result.get("ParsedResults", [{}])[0]
    text = parsed.get("ParsedText", "").strip()

    return {
        "status": "ok",
        "text": text
    }