export class FaceApi {
    constructor(url,csrf,onStatus=()=>{}) {Object.assign(this,{url,csrf,onStatus});}
    endpoint(action,params={}) {
        const url=new URL(this.url,globalThis.location?.href || 'http://localhost');
        for(const [key,value] of Object.entries({action,...params}))url.searchParams.set(key,value);
        return url.href;
    }
    async get(action,params={}) {
        const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
        try{const response=await fetch(this.endpoint(action,params),{credentials:'same-origin',cache:'no-store',signal:controller.signal});return await this.decode(response);}
        catch(error){if(error.name==='AbortError' || error instanceof TypeError)throw Error('Connection interrupted. Retry safely.');throw error;}
        finally{clearTimeout(timeout);}
    }

    async decode(response) {
        let result;
        try { result=await response.json(); } catch {throw Error('Server response unavailable; retry safely');}
        if(!response.ok || result.error) throw Object.assign(Error(result.error_msg || result.message || 'Face request failed'),{confirmed:response.status<500,code:result.error_msg,httpStatus:response.status});
        return result.data;
    }
    async post(action,payload,signal) {
        const body=JSON.stringify(payload); // identical payload and key for every retry
        for(let attempt=0;attempt<3;attempt++) {
            if(signal?.aborted) throw new DOMException('Cancelled','AbortError');
            const controller=new AbortController();
            const abort=()=>controller.abort();signal?.addEventListener('abort',abort,{once:true});
            const timeout=setTimeout(()=>controller.abort(),15000);
            try {
                const response=await fetch(this.endpoint(action),{method:'POST',credentials:'same-origin',
                    headers:{'Content-Type':'application/json','X-Face-CSRF':this.csrf},body,signal:controller.signal});
                if(response.status===503 || response.status===429) throw Object.assign(Error('Face service temporarily unavailable'),{httpStatus:response.status,confirmed:false});
                return await this.decode(response);
            } catch(error) {
                if(signal?.aborted) throw new DOMException('Cancelled','AbortError');
                // HTTP validation/auth failures are not connectivity retries.
                if(error.name!=='AbortError' && !(error instanceof TypeError) && error.message!=='Face service temporarily unavailable') throw error;
                if(attempt===2){if(error.httpStatus===503 || error.httpStatus===429)throw error;throw Error('Connection interrupted. Retry will check the same saved request.');}
                this.onStatus(error.httpStatus===503?'Server unavailable. Reconnecting...':error.httpStatus===429?'Please wait. Reconnecting...':'Connection lost. Reconnecting...');
                await new Promise((resolve,reject)=>{
                    const stop=()=>{clearTimeout(timer);reject(new DOMException('Cancelled','AbortError'));};
                    const timer=setTimeout(()=>{signal?.removeEventListener('abort',stop);resolve();},1000*(attempt+1));
                    signal?.addEventListener('abort',stop,{once:true});
                });
            } finally {clearTimeout(timeout);signal?.removeEventListener('abort',abort);}
        }
    }
}
