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

    if isinstance(hf_result, dict) and "error" in hf_result:
        raise HTTPException(
            status_code=502,
            detail=f"HuggingFace error: {hf_result['error']}"
        )

    if isinstance(hf_result, list):
        if len(hf_result) == 0:
            raise HTTPException(502, detail="Empty HF response")

        first = hf_result[0]

        if isinstance(first, list):
            top = first[0]
        elif isinstance(first, dict):
            top = first
        else:
            raise HTTPException(502, detail=f"Unknown HF format: {hf_result}")
    else:
        raise HTTPException(502, detail=f"Unexpected HF response: {hf_result}")

    label = top.get("label", "unknown")
    score = float(top.get("score", 0.0))

    return {
        "service": "text-service",
        "status": "ok",
        "model": HF_MODEL,
        "data": {
            "label": label,
            "confidence": round(score, 4),
        },
        "latency_ms": int((time.time() - start) * 1000)
    }
