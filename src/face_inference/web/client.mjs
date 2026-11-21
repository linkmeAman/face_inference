// Host application adapter. No PHP, Angular, CCC sessions or browser storage.
export class FaceClient {
    constructor({base='',token='',fetcher=(...args)=>globalThis.fetch(...args),timeout=10000}={}) {
        Object.assign(this,{base:base.replace(/\/$/,''),token,fetcher,timeout,pending:null});
    }
    async get(path) {
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),this.timeout);
        try{
            const response=await this.fetcher(this.base+path,{headers:{Authorization:'Bearer '+this.token},cache:'no-store',signal:controller.signal});
            return await this.decode(response);
        }finally{clearTimeout(timer);}
    }
    async decode(response) {
        const body=await response.json();
        if(!response.ok || body.error){const error=Error(body.error_msg || 'Face service unavailable');error.confirmed=response.status<500 && response.status!==429;error.code=body.code;throw error;}
        return body.data;
    }
    async send(path,body,method='POST') {
        if(this.pending)throw Error('Resolve the previous request with Retry save before starting another');
        const request={path,method,body:{...body,idempotency_key:body.idempotency_key || crypto.randomUUID()}};
        this.pending=request;
        return this.perform(request);
    }
    async perform(request) {
        let last;
        for(let attempt=0;attempt<3;attempt++){
            const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),this.timeout);
            try{
                const response=await this.fetcher(this.base+request.path,{method:request.method,
                    headers:{Authorization:'Bearer '+this.token,'Content-Type':'application/json'},
                    body:JSON.stringify(request.body),signal:controller.signal});
                const result=await this.decode(response);
                if(this.pending===request)this.pending=null;
                return result;
            }catch(error){last=error;if(error.confirmed){if(this.pending===request)this.pending=null;throw error;}}
            finally{clearTimeout(timer);}
            if(attempt<2)await new Promise(resolve=>setTimeout(resolve,250*(attempt+1)));
        }
        throw Error((last?.message || 'Connection interrupted')+'. Retry save to recover the same request.');
    }
    async retry(){if(!this.pending)throw Error('No unresolved request');return this.perform(this.pending);}
}
