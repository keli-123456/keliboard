import{e as p,q as a}from"../index.js";/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const r=[["path",{d:"M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z",key:"18887p"}]],n=p("message-square",r),m={fetch:e=>a.post("/ticket/fetch",e),reply:(e,s,o)=>{const t=new FormData;return t.append("id",String(e)),t.append("message",s||""),(o||[]).forEach(c=>t.append("images[]",c)),a.post("/ticket/reply",t)},close:e=>a.post("/ticket/close",{id:e}),autoReplyStats:(e=7)=>a.get("/ticket/autoReplyStats",{params:{days:e}})};export{n as M,m as t};
