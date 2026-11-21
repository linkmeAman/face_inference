import { useEffect, useRef } from "react";
import "face-capture";

export function FaceCapture({ apiUrl, token, collection, onResult }) {
  const ref = useRef(null);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const handler = (e) => onResult(e.detail);
    el.addEventListener("face-result", handler);
    return () => el.removeEventListener("face-result", handler);
  }, [onResult]);
  return (
    <face-capture
      ref={ref}
      api-url={apiUrl}
      token={token}
      collection={collection}
      mode="recognize"
      autostart
    />
  );
}
