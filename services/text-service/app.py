from fastapi import FastAPI
from pydantic import BaseModel
import time

app = FastAPI(title="Text Toxicity Service")

class Request(BaseModel):
    text: str

@app.get("/health")
def health():
    return {"status": "ok", "service": "text-service"}

@app.post("/analyze")
def analyze(req: Request):
    start = time.time()

    toxicity = 0.9 if "bad" in req.text.lower() else 0.1
    explanation = [{"token": "bad", "weight": 0.6}] if toxicity > 0.5 else []

    return {
        "service": "text-service",
        "status": "ok",
        "data": {
            "toxicity": toxicity,
            "explanation": explanation
        },
        "latency_ms": int((time.time() - start) * 1000)
    }
