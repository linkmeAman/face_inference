// Which way the active camera faces decides one thing only: whether the DISPLAYED
// preview is flipped (selfie view). It never touches the frames sent to YuNet/SFace.
//
// `track.getSettings().facingMode` is authoritative when present, but many
// browsers and almost every desktop/laptop webcam omit it, so the fallbacks below
// are ordered from most to least reliable and never throw.
const FRONT=/\b(front|user|selfie|facetime|face[\s-]?time|integrated|built[\s-]?in|webcam)\b|facing front/i;
const REAR=/\b(back|rear|environment|world)\b|facing back/i;
const SIDES=['user','environment'];
const side=value=>{const text=String(value || '').toLowerCase();return SIDES.includes(text)?text:'';};

export function isMobileBrowser(nav=globalThis.navigator) {
    const ua=String(nav?.userAgent || '');
    // iPadOS reports a desktop Mac user agent but is touch-first.
    return /Android|iPhone|iPad|iPod|Mobile/i.test(ua) || (/Macintosh/i.test(ua) && Number(nav?.maxTouchPoints) > 1);
}

// Returns {facing:'user'|'environment', source}. Sources, best first:
//   settings      the stream's own facingMode
//   capabilities  a single advertised facingMode
//   label         device label such as "Front Camera", "camera2 0, facing back"
//   requested     phones with several cameras and no metadata: what we asked for
//   default       a lone / desktop webcam faces the person using it
export function resolveCameraFacing({settings={},capabilities={},label='',requested='',deviceCount=0,mobile=false}={}) {
    const direct=side(settings?.facingMode);
    if(direct)return {facing:direct,source:'settings'};
    const advertised=[].concat(capabilities?.facingMode || []).map(side).filter(Boolean);
    if(advertised.length===1)return {facing:advertised[0],source:'capabilities'};
    const text=String(label || '');
    const rear=REAR.test(text),front=FRONT.test(text);
    if(rear && !front)return {facing:'environment',source:'label'};
    if(front && !rear)return {facing:'user',source:'label'};
    if(mobile && deviceCount>1 && side(requested))return {facing:side(requested),source:'requested'};
    return {facing:'user',source:'default'};
}
