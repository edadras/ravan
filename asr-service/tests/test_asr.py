import os
import sys

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app.main import app, is_question  # noqa: E402


@pytest.mark.parametrize("text,lang,expected", [
    ("رابطه شما با خانواده چطور است؟", "fa", True),
    ("رابطه شما با خانواده چطور است", "fa", True),
    ("این هفته خیلی خسته بودم", "fa", False),
    ("How are you sleeping these days", "en", True),
    ("I think that is a good plan.", "en", False),
    ("Uykunuz nasıl", "tr", True),
    ("Bu hafta iyi hissettiniz mi", "tr", True),
    ("Bugün hava güzel.", "tr", False),
])
def test_question_heuristic(text, lang, expected):
    assert is_question(text, lang) is expected


def test_transcribe_with_fake_backend_offsets_and_flags():
    c = TestClient(app)
    r = c.post("/transcribe", files={"audio": ("chunk.webm", b"x" * 9000, "audio/webm")}, data={"language": "fa", "speaker": "clinician", "t_offset_ms": 12000})
    assert r.status_code == 200
    j = r.json()
    assert j["backend"] == "fake" and j["language"] == "fa"
    assert j["segments"][0]["t_start_ms"] == 12000 and j["segments"][1]["t_start_ms"] == 14000
    assert j["segments"][0]["speaker"] == "clinician"
    assert c.post("/transcribe", files={"audio": ("c.webm", b"", "audio/webm")}, data={"language": "fa"}).status_code == 422
    assert c.post("/transcribe", files={"audio": ("c.webm", b"abc", "audio/webm")}, data={"language": "de"}).status_code == 422


def test_token_required_when_set(monkeypatch):
    monkeypatch.setattr("app.main.API_TOKEN", "t")
    c = TestClient(app)
    assert c.post("/transcribe", files={"audio": ("c.webm", b"abc", "audio/webm")}, data={"language": "en"}).status_code == 401
    assert c.post("/transcribe", files={"audio": ("c.webm", b"abc", "audio/webm")}, data={"language": "en"}, headers={"Authorization": "Bearer t"}).status_code == 200
