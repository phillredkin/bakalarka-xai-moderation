from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import os
import requests
import time

import torch
from transformers import AutoTokenizer, AutoModelForSequenceClassification
from captum.attr import IntegratedGradients

app = FastAPI(title="Text Toxicity Service (XAI)")

HF_TOKEN = os.getenv("HF_TOKEN")
HF_MODEL = "unitary/toxic-bert"
HF_URL = f"https://router.huggingface.co/hf-inference/models/{HF_MODEL}"


class AnalyzeRequest(BaseModel):
    text: str

tokenizer = AutoTokenizer.from_pretrained(HF_MODEL)
model = AutoModelForSequenceClassification.from_pretrained(HF_MODEL)
model.eval()

def forward_func(embeds, attention_mask):
    outputs = model(
        inputs_embeds=embeds,
        attention_mask=attention_mask
    )
    return outputs.logits


ig = IntegratedGradients(forward_func)

def explain_ig(text: str, target_label: int = 1, max_tokens: int = 8):
    inputs = tokenizer(text, return_tensors="pt", truncation=True)
    input_ids = inputs["input_ids"]
    attention_mask = inputs["attention_mask"]

    embeddings = model.bert.embeddings.word_embeddings(input_ids)

    baseline = torch.zeros_like(embeddings)

    attributions = ig.attribute(
        embeddings,
        baselines=baseline,
        additional_forward_args=(attention_mask,),
        target=target_label,
        n_steps=20
    )

    token_scores = attributions.sum(dim=-1).squeeze(0)
    tokens = tokenizer.convert_ids_to_tokens(input_ids[0])

    words = []
    current_word = ""
    current_score = 0.0

    for token, score in zip(tokens, token_scores):
        if token in tokenizer.all_special_tokens:
            continue

        score = float(score)

        if token.startswith("##"):
            current_word += token[2:]
            current_score += score
        else:
            if current_word:
                words.append({
                    "token": current_word,
                    "weight": round(current_score, 4)
                })
            current_word = token
            current_score = score

    if current_word:
        words.append({
            "token": current_word,
            "weight": round(current_score, 4)
        })

    words = [w for w in words if w["weight"] > 0]

    if not words:
        return []

    words.sort(key=lambda x: x["weight"], reverse=True)

    total_weight = sum(w["weight"] for w in words)

    for w in words:
        w["percent"] = round((w["weight"] / total_weight) * 100, 2)

    return words[:max_tokens]


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

    payload = {"inputs": req.text}

    try:
        r = requests.post(HF_URL, headers=headers, json=payload, timeout=15)
    except Exception as e:
        raise HTTPException(status_code=502, detail=str(e))

    if r.status_code != 200:
        raise HTTPException(status_code=502, detail=r.text)

    hf_result = r.json()
    first = hf_result[0]
    top = first[0] if isinstance(first, list) else first

    label = top.get("label", "unknown")
    confidence = float(top.get("score", 0.0))

    toxic_keywords = []

    if label == "toxic" and confidence >= 0.6:
        try:
            toxic_keywords = explain_ig(req.text)
        except Exception:
            toxic_keywords = []

    confidence_percent = round(confidence * 100, 2)

    return {
        "service": "text-service",
        "status": "ok",
        "model": HF_MODEL,
        "data": {
            "label": label,
            "confidence": confidence_percent,
            "toxic_keywords": toxic_keywords
        },
        "latency_ms": int((time.time() - start) * 1000)
    }
