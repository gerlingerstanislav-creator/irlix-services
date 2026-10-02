import { createBrowserAuth } from '@irlix/auth';
const nativeFetch=window.fetch.bind(window);const browserAuth=createBrowserAuth({storagePrefix:'irlix.platform.auth',defaultReturnTo:'/equipment/'});let installed=false;
const protectedApi=(input)=>{const raw=typeof input==='string'?input:input?.url;if(!raw)return false;const url=new URL(raw,window.location.origin);return url.origin===window.location.origin&&url.pathname.startsWith('/api/');};
export const auth={async init(){const ok=await browserAuth.init();if(ok&&!installed){installed=true;window.fetch=(input,init={})=>protectedApi(input)?browserAuth.fetch(input,init):nativeFetch(input,init);}return ok;},logout:()=>browserAuth.logout(),get user(){return browserAuth.user;}};
