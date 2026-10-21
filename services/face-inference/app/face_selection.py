"""Server-authoritative FaceGuide geometry validation and face selection."""

from __future__ import annotations

import math
from typing import Any, Dict, Mapping, Optional, Sequence, Tuple


CAPTURE_CONTEXT_VERSION = "face-capture-v1"


class CaptureContextError(ValueError):
    pass


def _number(value: Any, name: str) -> float:
    try:
        result = float(value)
    except (TypeError, ValueError) as exc:
        raise CaptureContextError(f"{name} must be numeric") from exc
    if not math.isfinite(result):
        raise CaptureContextError(f"{name} must be finite")
    return result


def validate_capture_context(context: Optional[Mapping[str, Any]]) -> Optional[Dict[str, Any]]:
    """Return a bounded, JSON-safe context or ``None`` for legacy callers."""

    if context is None:
        return None
    if not isinstance(context, Mapping):
        raise CaptureContextError("capture_context must be an object")
    if context.get("version") != CAPTURE_CONTEXT_VERSION:
        raise CaptureContextError("capture_context version is unsupported")

    frame = context.get("frame")
    display = context.get("display")
    guide = context.get("guide")
    if not isinstance(frame, Mapping) or not isinstance(display, Mapping) or not isinstance(guide, Mapping):
        raise CaptureContextError("capture_context frame, display, and guide are required")

    frame_width = _number(frame.get("width"), "frame.width")
    frame_height = _number(frame.get("height"), "frame.height")
    display_width = _number(display.get("width"), "display.width")
    display_height = _number(display.get("height"), "display.height")
    if not (1 <= frame_width <= 8192 and 1 <= frame_height <= 8192):
        raise CaptureContextError("frame dimensions are outside the allowed range")
    if not (1 <= display_width <= 8192 and 1 <= display_height <= 8192):
        raise CaptureContextError("display dimensions are outside the allowed range")

    content = display.get("content_rect")
    if not isinstance(content, Mapping):
        raise CaptureContextError("display.content_rect is required")
    content_rect = {name: _number(content.get(name), f"display.content_rect.{name}") for name in ("x", "y", "width", "height")}
    if content_rect["width"] <= 0 or content_rect["height"] <= 0:
        raise CaptureContextError("display content dimensions must be positive")
    tolerance = 1.0
    if (
        content_rect["x"] < -tolerance
        or content_rect["y"] < -tolerance
        or content_rect["x"] + content_rect["width"] > display_width + tolerance
        or content_rect["y"] + content_rect["height"] > display_height + tolerance
    ):
        raise CaptureContextError("display content rectangle is outside the display")
    contain_scale = min(display_width / frame_width, display_height / frame_height)
    expected_content = {
        "x": (display_width - frame_width * contain_scale) / 2.0,
        "y": (display_height - frame_height * contain_scale) / 2.0,
        "width": frame_width * contain_scale,
        "height": frame_height * contain_scale,
    }
    if any(abs(content_rect[name] - expected_content[name]) > 2.0 for name in expected_content):
        raise CaptureContextError("display content rectangle does not match object-fit contain")

    ellipse = {name: _number(guide.get(name), f"guide.{name}") for name in ("cx", "cy", "rx", "ry")}
    if not (0 <= ellipse["cx"] <= 1 and 0 <= ellipse["cy"] <= 1 and 0 < ellipse["rx"] <= 0.5 and 0 < ellipse["ry"] <= 0.5):
        raise CaptureContextError("guide ellipse is outside normalized frame coordinates")
    if ellipse["cx"] - ellipse["rx"] < -1e-6 or ellipse["cx"] + ellipse["rx"] > 1 + 1e-6:
        raise CaptureContextError("guide ellipse exceeds the horizontal frame edge")
    if ellipse["cy"] - ellipse["ry"] < -1e-6 or ellipse["cy"] + ellipse["ry"] > 1 + 1e-6:
        raise CaptureContextError("guide ellipse exceeds the vertical frame edge")

    rotation = int(_number(context.get("rotation", 0), "rotation"))
    if rotation not in (0, 90, 180, 270):
        raise CaptureContextError("rotation must be 0, 90, 180, or 270")
    object_fit = str(display.get("object_fit", ""))
    if object_fit != "contain":
        raise CaptureContextError("object_fit must be contain")
    capture_id = str(context.get("capture_id", ""))
    if not capture_id or len(capture_id) > 128 or not all(ch.isalnum() or ch in "._:-" for ch in capture_id):
        raise CaptureContextError("capture_id is invalid")
    source = str(context.get("source", "canvas-video"))
    if source != "canvas-video":
        raise CaptureContextError("capture source is unsupported")

    return {
        "version": CAPTURE_CONTEXT_VERSION,
        "capture_id": capture_id,
        "frame": {"width": int(round(frame_width)), "height": int(round(frame_height))},
        "display": {
            "width": round(display_width, 3),
            "height": round(display_height, 3),
            "content_rect": {key: round(value, 3) for key, value in content_rect.items()},
            "object_fit": object_fit,
        },
        "guide": {key: round(value, 6) for key, value in ellipse.items()},
        "mirrored_preview": bool(context.get("mirrored_preview", False)),
        "rotation": rotation,
        "source": source,
    }


def _box(face: Any, image_width: int, image_height: int) -> Tuple[float, float, float, float]:
    x, y, width, height = (float(value) for value in face[:4])
    left = max(0.0, min(1.0, x / max(1.0, image_width)))
    top = max(0.0, min(1.0, y / max(1.0, image_height)))
    right = max(0.0, min(1.0, (x + max(0.0, width)) / max(1.0, image_width)))
    bottom = max(0.0, min(1.0, (y + max(0.0, height)) / max(1.0, image_height)))
    return (
        left,
        top,
        max(0.0, right - left),
        max(0.0, bottom - top),
    )


def _inside_ellipse(x: float, y: float, guide: Mapping[str, float]) -> bool:
    dx = (x - guide["cx"]) / guide["rx"]
    dy = (y - guide["cy"]) / guide["ry"]
    return dx * dx + dy * dy <= 1.0


def _overlap(box: Tuple[float, float, float, float], guide: Mapping[str, float]) -> Tuple[float, float]:
    """Approximate rectangle/ellipse overlap deterministically on a dense grid."""

    x, y, width, height = box
    if width <= 0 or height <= 0:
        return 0.0, 0.0
    samples = 41
    inside = 0
    for row in range(samples):
        py = y + height * ((row + 0.5) / samples)
        for column in range(samples):
            px = x + width * ((column + 0.5) / samples)
            if _inside_ellipse(px, py, guide):
                inside += 1
    face_overlap = inside / float(samples * samples)
    intersection = face_overlap * width * height
    ellipse_area = math.pi * guide["rx"] * guide["ry"]
    union = width * height + ellipse_area - intersection
    return face_overlap, intersection / union if union > 0 else 0.0


def select_face(faces: Sequence[Any], image_width: int, image_height: int, context: Optional[Mapping[str, Any]]) -> Dict[str, Any]:
    """Select the unambiguous target face, retaining legacy behavior without context."""

    validated = validate_capture_context(context)
    if validated is None:
        selected = 0 if len(faces) == 1 else None
        reason = "SELECTED" if selected is not None else ("NO_FACE" if len(faces) == 0 else "MULTIPLE_FACES")
        return {
            "mode": "legacy-exact-one",
            "detected_face_count": len(faces),
            "guide_candidate_count": len(faces),
            "selected_face_index": selected,
            "ambiguous": len(faces) > 1,
            "reason_code": reason,
            "faces": [],
        }

    guide = validated["guide"]
    items = []
    for index, face in enumerate(faces):
        normalized = _box(face, image_width, image_height)
        x, y, width, height = normalized
        centre = (x + width / 2.0, y + height / 2.0)
        center_inside = _inside_ellipse(centre[0], centre[1], guide)
        overlap, iou = _overlap(normalized, guide)
        guide_valid = center_inside or overlap >= 0.50
        items.append({
            "index": index,
            "box": {"x": round(x, 6), "y": round(y, 6), "width": round(width, 6), "height": round(height, 6)},
            "confidence": round(float(face[14]) if len(face) > 14 else 1.0, 4),
            "center_inside": center_inside,
            "overlap": round(overlap, 4),
            "iou": round(iou, 4),
            "guide_valid": guide_valid,
        })

    candidates = [item for item in items if item["guide_valid"]]
    inside_candidates = [item for item in candidates if item["center_inside"]]
    tier = inside_candidates if inside_candidates else candidates
    tier.sort(key=lambda item: item["overlap"], reverse=True)
    selected = None
    ambiguous = False
    reason = "NO_FACE_IN_GUIDE" if len(faces) > 0 else "NO_FACE"
    if tier:
        if len(tier) > 1 and float(tier[0]["overlap"]) - float(tier[1]["overlap"]) < 0.15:
            ambiguous = True
            reason = "MULTIPLE_FACES_IN_GUIDE"
        else:
            selected = int(tier[0]["index"])
            reason = "SELECTED"

    return {
        "mode": "face-guide-v1",
        "detected_face_count": len(faces),
        "guide_candidate_count": len(candidates),
        "selected_face_index": selected,
        "ambiguous": ambiguous,
        "reason_code": reason,
        "guide": guide,
        "faces": items,
    }


__all__ = ["CAPTURE_CONTEXT_VERSION", "CaptureContextError", "select_face", "validate_capture_context"]
