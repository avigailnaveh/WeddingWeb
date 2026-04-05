# Agent.py
# -*- coding: utf-8 -*-

import os
import json
from typing import Any, Dict, List, Optional, Literal

from fastapi import FastAPI, HTTPException, Header
from pydantic import BaseModel, Field
from dotenv import load_dotenv
from openai import OpenAI

# =============================================================================
# ENV
# =============================================================================
load_dotenv()

OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
OPENAI_MODEL = os.getenv("OPENAI_MODEL", "gpt-5.2-mini")
MODEL_VERSION = os.getenv("MODEL_VERSION", "doctorita-recommendation-analysis-v1")

# מומלץ לשים טוקן כדי שרק השרת PHP יוכל לקרוא ל-Agent
AGENT_TOKEN = os.getenv("AGENT_TOKEN", "")  # אם ריק - לא בודק טוקן

app = FastAPI(title="Doctorita Recommendation Analysis Agent", version=MODEL_VERSION)

client = OpenAI(api_key=OPENAI_API_KEY) if OPENAI_API_KEY else None


# =============================================================================
# Schemas
# =============================================================================
class AnalyzeIn(BaseModel):
    text: str = Field(min_length=2, max_length=5000)
    member_id: Optional[int] = None
    professional_id: Optional[int] = None


Sentiment = Literal["pos", "neg", "neu"]
ChildrenSignal = Literal["explicit_yes", "explicit_no", "unclear", "not_mentioned"]


class DoctorMetrics(BaseModel):
    professionalism: Optional[float] = Field(default=None, ge=0, le=1)
    empathy: Optional[float] = Field(default=None, ge=0, le=1)
    patience: Optional[float] = Field(default=None, ge=0, le=1)
    availability: Optional[float] = Field(default=None, ge=0, le=1)
    clear_explanation: Optional[float] = Field(default=None, ge=0, le=1)
    cost: Optional[float] = Field(default=None, ge=0, le=1)


class AnalysisOut(BaseModel):
    sentiment: Sentiment
    sentiment_confidence: float = Field(ge=0, le=1)

    doctor_name: Optional[str] = None
    doctor_title: Optional[str] = None

    hmo: Optional[str] = None
    insurance: Optional[str] = None
    languages: List[str] = Field(default_factory=list)
    specialties: List[str] = Field(default_factory=list)

    topics: List[str] = Field(default_factory=list)

    works_with_children: int = Field(default=0, ge=0, le=1)
    children_signal: Optional[ChildrenSignal] = None
    children_evidence: Optional[str] = None

    doctor_metrics: Optional[DoctorMetrics] = None
    extracted_entities: Dict[str, Any] = Field(default_factory=dict)


# =============================================================================
# Helpers
# =============================================================================
def _require_auth(authorization: Optional[str]) -> None:
    if not AGENT_TOKEN:
        return
    if not authorization:
        raise HTTPException(status_code=401, detail="Missing Authorization header")
    if not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Invalid Authorization scheme")
    token = authorization.split(" ", 1)[1].strip()
    if token != AGENT_TOKEN:
        raise HTTPException(status_code=403, detail="Forbidden")


def run_analysis_with_openai(text: str) -> Dict[str, Any]:
    """
    שולח ל-OpenAI ומחזיר dict שמתאים ל-AnalysisOut.
    """

    if not client:
        raise RuntimeError("Missing OPENAI_API_KEY")

    system = """
אתה מנוע ניתוח המלצות לרופאים בעברית.
הקלט הוא טקסט המלצה חופשית של משתמש.

החזר JSON בלבד לפי הסכמה:
{
  "sentiment": "pos|neg|neu",
  "sentiment_confidence": 0..1,
  "doctor_name": string|null,
  "doctor_title": string|null,
  "hmo": string|null,
  "insurance": string|null,
  "languages": [string],
  "specialties": [string],
  "topics": [string],
  "works_with_children": 0|1,
  "children_signal": "explicit_yes|explicit_no|unclear|not_mentioned"|null,
  "children_evidence": string|null,
  "doctor_metrics": {
     "professionalism": 0..1|null,
     "empathy": 0..1|null,
     "patience": 0..1|null,
     "availability": 0..1|null,
     "clear_explanation": 0..1|null,
     "cost": 0..1|null
  }|null,
  "extracted_entities": object
}
הסבר על השדות:
professionalism - אם המשתמש ציין שהרופא מקצועי אז 1 אחרת 0
empathy - אם המשתמש ציין שהרופא מביע אמפתיה אז 1 אחרת 0
patience - אם המשתמש ציין שהרופא סובלני אז 1 אחרת 0
availability - אם המשתמש ציין שהרופא זמין אז 1 אחרת 0
clear_explanation - אם המשתמש ציין שהרופא מסביר ברור אז 1 אחרת 0
cost - אם המשתמש ציין שעלות הטיפול זולה אז 1 אחרת אם ציין שיקרה 0
אם המשתמש לא ציין אחד מהשדות אז null

חוקים:
- אל תמציא פרטים. אם לא בטוח -> null/ריק.
- languages/specialties/topics: רק מה שמוזכר או משתמע בבירור.
- works_with_children: 1 רק אם יש אינדיקציה די ברורה (ילדים/תינוקות/פעוטות וכו).
- doctor_metrics: אם אין בסיס מספיק — שים null לכל שדה או doctor_metrics=null.
"""

    user = f"TEXT:\n{text}\n"

    resp = client.chat.completions.create(
        model=OPENAI_MODEL,
        messages=[
            {"role": "system", "content": system.strip()},
            {"role": "user", "content": user},
        ],
        temperature=0.2,
        response_format={"type": "json_object"},
    )

    content = resp.choices[0].message.content or ""
    
    # Parse JSON
    try:
        data = json.loads(content)
    except Exception as e:
        print(f"ERROR: Failed to parse JSON from GPT: {e}")
        print(f"GPT Response: {content[:500]}")
        raise RuntimeError(f"Agent returned non-JSON: {content[:200]}")

    # Print what GPT returned for debugging
    print(f"DEBUG: GPT returned data: {json.dumps(data, ensure_ascii=False, indent=2)}")

    # Special handling for doctor_metrics - ensure values are within range
    if "doctor_metrics" in data and data["doctor_metrics"]:
        metrics = data["doctor_metrics"]
        if isinstance(metrics, dict):
            for key in ["professionalism", "empathy", "patience", "availability", "clear_explanation", "cost"]:
                if key in metrics and metrics[key] is not None:
                    try:
                        val = float(metrics[key])
                        # Clamp to 0-1 range
                        metrics[key] = max(0.0, min(1.0, val))
                    except (ValueError, TypeError):
                        print(f"WARNING: Invalid value for {key}: {metrics[key]}, setting to None")
                        metrics[key] = None
            data["doctor_metrics"] = metrics

    # Validation with better error handling
    try:
        validated = AnalysisOut(**data).model_dump()
        print(f"DEBUG: Validation successful")
        return validated
    except Exception as e:
        print(f"ERROR: Validation failed: {e}")
        print(f"ERROR: Data that failed validation: {json.dumps(data, ensure_ascii=False, indent=2)}")
        
        # Try to provide more specific error info
        if "doctor_metrics" in data and data["doctor_metrics"]:
            print(f"ERROR: doctor_metrics value: {data['doctor_metrics']}")
            print(f"ERROR: doctor_metrics type: {type(data['doctor_metrics'])}")
        
        raise RuntimeError(f"Validation error: {str(e)}")


# =============================================================================
# Routes
# =============================================================================
@app.post("/analyze")
def analyze(payload: AnalyzeIn, authorization: Optional[str] = Header(default=None)) -> Dict[str, Any]:
    _require_auth(authorization)

    try:
        analysis = run_analysis_with_openai(payload.text)
    except HTTPException:
        raise
    except Exception as e:
        error_msg = str(e)
        print(f"ERROR in analyze endpoint: {error_msg}")
        
        # Return a fallback response instead of 500 error
        return {
            "ok": False,
            "error": error_msg,
            "model_version": MODEL_VERSION,
            "analysis": {
                "sentiment": "neu",
                "sentiment_confidence": 0.0,
                "doctor_name": None,
                "doctor_title": None,
                "hmo": None,
                "insurance": None,
                "languages": [],
                "specialties": [],
                "topics": [],
                "works_with_children": 0,
                "children_signal": "not_mentioned",
                "children_evidence": None,
                "doctor_metrics": None,
                "extracted_entities": {},
            }
        }

    return {
        "ok": True,
        "model_version": MODEL_VERSION,
        "analysis": analysis,
    }


@app.get("/health")
def health() -> Dict[str, Any]:
    return {
        "ok": True,
        "model": OPENAI_MODEL,
        "model_version": MODEL_VERSION,
        "auth_enabled": bool(AGENT_TOKEN),
    }