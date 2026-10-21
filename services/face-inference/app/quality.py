"""Image quality helpers. Frames are inspected in memory only."""

from __future__ import annotations

import math
from dataclasses import dataclass
from typing import Any, Dict, List, Tuple


LANDMARK_NAMES = ("right_eye", "left_eye", "nose", "right_mouth", "left_mouth")


@dataclass(frozen=True)
class ClientPlanarLivenessCheck:
    """Validation result for the browser-provided depth signal."""

    accepted: bool
    planar_spoof_flag: bool
    hard_reject: bool
    reason: str


def validate_client_planar_z_variance(
    value: Any, threshold: float, shadow_mode: bool,
) -> ClientPlanarLivenessCheck:
    """Validate Layer 1 before MN3 PAD and SFace matching.

    Browser data is supplemental and untrusted. Only the boolean flag/reason
    belongs in logs; the numeric value must not be logged.
    """

    if not isinstance(value, (int, float)) or isinstance(value, bool) or not math.isfinite(float(value)):
        # Browser telemetry is untrusted and device-dependent. Missing data is
        # observable for diagnostics but never blocks MN3/SFace evaluation.
        return ClientPlanarLivenessCheck(
            accepted=True,
            planar_spoof_flag=False,
            hard_reject=False,
            reason="CLIENT_PLANAR_Z_UNAVAILABLE",
        )
    elif float(value) < threshold:
        failed, reason = True, "SPOOF_PLANAR_ATTACK"
    else:
        failed, reason = False, "OK"
    return ClientPlanarLivenessCheck(
        accepted=True,
        planar_spoof_flag=failed,
        hard_reject=False,
        reason=reason,
    )


def face_landmarks(face: Any) -> Dict[str, List[float]]:
    values = [float(value) for value in face[4:14]]
    return {
        name: [values[index * 2], values[index * 2 + 1]]
        for index, name in enumerate(LANDMARK_NAMES)
    }


def pose_yaw_indicator(face: Any) -> float:
    """Return signed horizontal nose displacement normalized by eye distance."""

    landmarks = face_landmarks(face)
    right_eye, left_eye = landmarks["right_eye"], landmarks["left_eye"]
    nose = landmarks["nose"]
    eye_distance = math.hypot(left_eye[0] - right_eye[0], left_eye[1] - right_eye[1])
    if eye_distance <= 0.0:
        return 0.0
    eye_mid_x = (right_eye[0] + left_eye[0]) / 2.0
    return (nose[0] - eye_mid_x) / eye_distance


def assess_face_quality(image: Any, face: Any, face_count: int, guide: Any = None, detected_face_count: Any = None) -> Dict[str, Any]:
    """Return explainable acquisition quality evidence for one YuNet face."""

    reasons: List[str] = []
    if image is None or face is None:
        return {"accepted": False, "reasons": ["NO_FACE"]}
    if face_count != 1:
        reasons.append("MULTIPLE_FACES" if face_count > 1 else "NO_FACE")

    try:
        import cv2  # type: ignore
    except ImportError:
        return {"accepted": False, "reasons": reasons + ["QUALITY_UNAVAILABLE"]}

    image_height, image_width = image.shape[:2]
    x, y, width, height = (float(value) for value in face[:4])
    x1 = max(0, int(x))
    y1 = max(0, int(y))
    x2 = min(image_width, int(x + width))
    y2 = min(image_height, int(y + height))
    crop = image[y1:y2, x1:x2]

    # Normalized bounding box coordinates
    u = x / float(max(1, image_width))
    v = y / float(max(1, image_height))
    uw = width / float(max(1, image_width))
    vh = height / float(max(1, image_height))

    area_ratio = max(0.0, width * height) / float(max(1, image_width * image_height))
    min_pixel_size = min(width, height) >= 80.0
    min_area = area_ratio >= 0.025
    too_small = uw < 0.20 or vh < 0.25 or not min_pixel_size or not min_area
    too_large = uw > 0.55 or vh > 0.65
    size_ok = not too_small and not too_large
    if too_small:
        reasons.append("FACE_TOO_SMALL")
    elif too_large:
        reasons.append("FACE_TOO_CLOSE")

    # Camera frame edge margin: face must not be clipped by the camera frame edges
    clipped = u < 0.03 or (u + uw) > 0.97 or v < 0.03 or (v + vh) > 0.97
    if clipped:
        reasons.append("FACE_CLIPPED_BY_FRAME")

    centre_x = x + width / 2.0
    centre_y = y + height / 2.0
    norm_cx = centre_x / float(max(1, image_width))
    norm_cy = centre_y / float(max(1, image_height))
    guide = guide if isinstance(guide, dict) else {"cx": 0.50, "cy": 0.46, "rx": 0.30, "ry": 0.36}
    guide_cx = float(guide.get("cx", 0.50))
    guide_cy = float(guide.get("cy", 0.46))
    guide_rx = max(0.01, float(guide.get("rx", 0.30)))
    guide_ry = max(0.01, float(guide.get("ry", 0.36)))
    offset_x = norm_cx - guide_cx
    offset_y = norm_cy - guide_cy
    centred_ok = abs(offset_x) <= guide_rx * (0.11 / 0.30) and abs(offset_y) <= guide_ry * (0.13 / 0.36)
    if not centred_ok:
        reasons.append("FACE_OFF_CENTRE")
        if offset_y > guide_ry * (0.13 / 0.36):
            reasons.append("FACE_TOO_LOW")
        elif offset_y < -guide_ry * (0.13 / 0.36):
            reasons.append("FACE_TOO_HIGH")
        if offset_x < -guide_rx * (0.11 / 0.30):
            reasons.append("FACE_TOO_LEFT")
        elif offset_x > guide_rx * (0.11 / 0.30):
            reasons.append("FACE_TOO_RIGHT")

    # Guide frame bounds (oval at center 0.50W, 0.46H, radii 0.30W, 0.36H)
    def _ellipse_dist(pu: float, pv: float) -> float:
        du = (pu - guide_cx) / guide_rx
        dv = (pv - guide_cy) / guide_ry
        return math.sqrt(du * du + dv * dv)

    # 1. Landmark containment: all 5 facial landmarks must be strictly within inner guide (distance <= 0.88)
    landmarks = face_landmarks(face)
    landmarks_inside = True
    landmark_dists: Dict[str, float] = {}
    for lm_name, (lx, ly) in landmarks.items():
        lu = lx / float(max(1, image_width))
        lv = ly / float(max(1, image_height))
        d = _ellipse_dist(lu, lv)
        landmark_dists[lm_name] = round(d, 3)
        if d > 0.88:
            landmarks_inside = False
            reasons.append(f"LANDMARK_{lm_name.upper()}_OUTSIDE")

    # 2. Bounding box perimeter containment: perimeter cardinal centers must be inside guide (distance <= 1.05)
    perimeter_points = [
        (norm_cx, v),          # Top center (crown/forehead)
        (norm_cx, v + vh),     # Bottom center (chin)
        (u, norm_cy),          # Left center
        (u + uw, norm_cy),     # Right center
    ]
    perimeter_ok = True
    for px, py in perimeter_points:
        if _ellipse_dist(px, py) > 1.05:
            perimeter_ok = False
            break

    if not perimeter_ok and "FACE_OUTSIDE_GUIDE" not in reasons:
        reasons.append("FACE_OUTSIDE_GUIDE")

    # 3. Eye level: eyes must be in the upper region of the guide (eye_v in [0.24, 0.52])
    right_eye, left_eye = landmarks["right_eye"], landmarks["left_eye"]
    nose = landmarks["nose"]
    eye_v = ((right_eye[1] + left_eye[1]) / 2.0) / float(max(1, image_height))
    eye_min = guide_cy - guide_ry * (0.46 - 0.24) / 0.36
    eye_max = guide_cy + guide_ry * (0.52 - 0.46) / 0.36
    eye_level_ok = eye_min <= eye_v <= eye_max
    if not eye_level_ok and "FACE_OFF_CENTRE" not in reasons:
        reasons.append("FACE_OFF_CENTRE")
        if eye_v > eye_max:
            reasons.append("FACE_TOO_LOW")
        else:
            reasons.append("FACE_TOO_HIGH")

    inside_guide = bool(landmarks_inside and perimeter_ok and centred_ok and size_ok and not clipped)
    if not inside_guide and "FACE_OUTSIDE_GUIDE" not in reasons:
        reasons.append("FACE_OUTSIDE_GUIDE")

    sharpness = 0.0
    exposure = 0.0
    contrast = 0.0
    clipped_dark_pct = 0.0
    clipped_bright_pct = 0.0
    illumination_balance = 1.0
    if crop.size:
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
        sharpness = float(cv2.Laplacian(gray, cv2.CV_64F).var())
        exposure = float(gray.mean())
        contrast = float(gray.std())
        total_pixels = float(max(1, gray.size))
        clipped_dark_pct = round(float((gray < 5).sum()) / total_pixels * 100.0, 3)
        clipped_bright_pct = round(float((gray > 250).sum()) / total_pixels * 100.0, 3)
        half_w = gray.shape[1] // 2
        if half_w > 0:
            left_mean = float(gray[:, :half_w].mean())
            right_mean = float(gray[:, half_w:].mean())
            denom = max(left_mean, right_mean, 1e-3)
            illumination_balance = round(min(left_mean, right_mean) / denom, 3)

    sharpness_ok = sharpness >= 35.0
    exposure_ok = 35.0 <= exposure <= 220.0
    contrast_ok = contrast >= 15.0 if crop.size else False
    clipping_ok = clipped_dark_pct <= 15.0 and clipped_bright_pct <= 15.0
    illumination_ok = illumination_balance >= 0.35

    if not sharpness_ok:
        reasons.append("BLUR")
    if not exposure_ok:
        reasons.append("POOR_LIGHTING")
    if not contrast_ok and crop.size:
        reasons.append("CONTRAST_LOW")
    if clipped_dark_pct > 15.0:
        reasons.append("CLIPPED_DARK")
    if clipped_bright_pct > 15.0:
        reasons.append("CLIPPED_BRIGHT")
    if not illumination_ok:
        reasons.append("UNBALANCED_LIGHT")

    eye_dx = left_eye[0] - right_eye[0]
    eye_dy = left_eye[1] - right_eye[1]
    eye_distance = math.hypot(eye_dx, eye_dy)
    tilt_degrees = abs(math.degrees(math.atan2(eye_dy, eye_dx))) if eye_distance else 90.0
    pose_yaw = pose_yaw_indicator(face)

    # Pitch indicator: vertical position of nose relative to eyes
    eye_mid_y = (right_eye[1] + left_eye[1]) / 2.0
    pitch_indicator = (nose[1] - eye_mid_y) / float(max(1.0, eye_distance))
    pitch_ok = 0.25 <= pitch_indicator <= 0.85
    yaw_ok = abs(pose_yaw) <= 0.22
    tilt_ok = tilt_degrees <= 20.0
    profile_ok = eye_distance >= max(1.0, width * 0.18) and abs(pose_yaw) <= 0.45
    facing_camera = bool(yaw_ok and tilt_ok and pitch_ok)

    if not tilt_ok:
        reasons.append("FACE_TILTED")
    if not yaw_ok:
        reasons.append("TURN_FACE_TO_CAMERA")
    if not pitch_ok:
        reasons.append("FACE_PITCH")
    if not profile_ok:
        reasons.append("PROFILE_POSE")

    detector_confidence = float(face[14]) if len(face) > 14 else 1.0
    centering_offset = round(math.hypot(offset_x, offset_y), 3)

    unique_reasons = sorted(list(set(reasons)))
    return {
        "accepted": not unique_reasons,
        "reasons": unique_reasons,
        "size_ok": size_ok,
        "sharpness_ok": sharpness_ok,
        "exposure_ok": exposure_ok,
        "contrast_ok": contrast_ok,
        "illumination_ok": illumination_ok,
        "clipping_ok": clipping_ok,
        "centred_ok": centred_ok,
        "inside_guide": inside_guide,
        "landmarks_inside": landmarks_inside,
        "facing_camera": facing_camera,
        "yaw_ok": yaw_ok,
        "tilt_ok": tilt_ok,
        "pitch_ok": pitch_ok,
        "profile_ok": profile_ok,
        "detector_confidence": round(detector_confidence, 4),
        "inter_eye_pixels": round(eye_distance, 2),
        "box": {"x": round(x, 2), "y": round(y, 2), "width": round(width, 2), "height": round(height, 2)},
        "pose_yaw": round(pose_yaw, 3),
        "pose_pitch": round(pitch_indicator, 3),
        "sharpness": round(sharpness, 3),
        "exposure": round(exposure, 3),
        "contrast": round(contrast, 3),
        "clipped_dark_pct": clipped_dark_pct,
        "clipped_bright_pct": clipped_bright_pct,
        "illumination_balance": illumination_balance,
        "face_area_ratio": round(area_ratio, 6),
        "tilt_degrees": round(tilt_degrees, 3),
        "norm_center": (round(norm_cx, 3), round(norm_cy, 3)),
        "centering_offset": centering_offset,
        "landmark_distances": landmark_dists,
        "quality_policy_version": "face-policy-v2",
        "detected_face_count": int(detected_face_count if detected_face_count is not None else face_count),
        "guide_candidate_count": int(face_count),
        "guide": {"cx": round(guide_cx, 6), "cy": round(guide_cy, 6), "rx": round(guide_rx, 6), "ry": round(guide_ry, 6)},
    }


def quality_flags(image: Any, face_count: int, face: Any = None, guide: Any = None, detected_face_count: Any = None) -> Tuple[bool, bool, bool]:
    """Return sharpness, exposure, and pose flags for an OpenCV image.

    The thresholds are deliberately conservative spike defaults and are not
    identity thresholds. Production calibration must be measured on the actual
    devices and venues before an automatic ACCEPT threshold is enabled.
    """

    if image is None or face_count != 1:
        return False, False, False
    if face is None:
        try:
            import cv2  # type: ignore
        except ImportError:
            return False, False, False
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        return float(cv2.Laplacian(gray, cv2.CV_64F).var()) >= 35.0, 20.0 <= float(gray.mean()) <= 235.0, True
    result = assess_face_quality(image, face, face_count, guide=guide, detected_face_count=detected_face_count)
    return bool(result["sharpness_ok"]), bool(result["exposure_ok"]), bool(result["tilt_ok"] and result["profile_ok"] and result["size_ok"] and result["centred_ok"] and result.get("yaw_ok", True))
