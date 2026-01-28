from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import os
import requests
import time

app = FastAPI(title="Text Toxicity Service")

HF_TOKEN = os.getenv("HF_TOKEN")
HF_MODEL = "unitary/toxic-bert"
HF_URL = f"https://router.huggingface.co/hf-inference/models/{HF_MODEL}"

class AnalyzeRequest(BaseModel):
    text: str


@app.get("/health")
def health():
    return {"status": "ok", "service": "text-service"}


@app.post("/analyze")
def analyze(req: AnalyzeRequest):
    start = time.time()

    if not HF_TOKEN:
        raise HTTPException(status_code=500, detail="HF_TOKEN not set")

    headers = {
        "Authorization": f"Bearer {HF_TOKEN}",
        "Content-Type": "application/json",
    }

    payload = {
        "inputs": req.text
    }

    try:
        r = requests.post(HF_URL, headers=headers, json=payload, timeout=15)
    except Exception as e:
        raise HTTPException(status_code=502, detail=str(e))

    if r.status_code != 200:
        raise HTTPException(
            status_code=502,
            detail=f"HuggingFace error: {r.text}"
        )

    hf_result = r.json()

    top = hf_result[0]
    label = top.get("label", "unknown")
    score = float(top.get("score", 0.0))

    return {
        "service": "text-service",
        "status": "ok",
        "model": HF_MODEL,
        "data": {
            "verdict": verdict,
            "label": label,
            "confidence": round(score, 4),
        },
        "latency_ms": int((time.time() - start) * 1000)
    }
