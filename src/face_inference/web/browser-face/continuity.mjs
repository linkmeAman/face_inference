import {cosine,normalize} from './core.mjs?v=stability-20261010-final';
// Capture-local continuity only. The server supplies existing approved (or
// explicitly TEST-only) decision settings. This never changes gallery matching.
export function continuityPolicy(config) {
    const policy=config.capture_continuity;
    if(!policy || !Number.isFinite(policy.same_min) || !Number.isFinite(policy.different_max)
        || policy.same_min>1 || policy.different_max< -1 || policy.different_max>policy.same_min
        || !Number.isInteger(policy.observations) || policy.observations<3 || policy.observations>5)return null;
    return policy;
}
export function continuityDecision(accepted,returning,policy) {
    if(!policy || !accepted.length)return 'UNAVAILABLE';
    if(returning.length<policy.observations)return 'VERIFYING';
    // An anchor never moves toward a rejected/uncertain returning face.
    // Check every retained reference as well as its normalized mean; one high
    // similarity or coherent wrong-face sequence cannot override another vote.
    const dimension=accepted[0].length;
    const anchor=normalize(accepted[0].map((_,i)=>accepted.reduce((sum,v)=>sum+v[i],0)/accepted.length),dimension);
    const references=[anchor,...accepted];
    const scores=returning.flatMap(vector=>references.map(reference=>cosine(vector,reference)));
    const coherent=returning.every((a,i)=>returning.slice(i+1).every(b=>cosine(a,b)>=policy.same_min));
    if(coherent && scores.every(score=>score>=policy.same_min))return 'SAME';
    if(coherent && scores.every(score=>score<policy.different_max))return 'DIFFERENT';
    return 'UNCERTAIN';
}
