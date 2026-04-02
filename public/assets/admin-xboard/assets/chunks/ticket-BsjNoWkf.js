import{d as s,m as t}from"../index.js";/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const n=[["path",{d:"M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z",key:"18887p"}]],d=s("message-square",n);/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const r=[["path",{d:"M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z",key:"1ffxy3"}],["path",{d:"m21.854 2.147-10.94 10.939",key:"12cjpa"}]],m=s("send",r),l={fetch:e=>t.post("/ticket/fetch",e),reply:(e,c,o)=>{const a=new FormData;return a.append("id",String(e)),a.append("message",c||""),(o||[]).forEach(p=>a.append("images[]",p)),t.post("/ticket/reply",a)},close:e=>t.post("/ticket/close",{id:e}),autoReplyStats:(e=7)=>t.get("/ticket/autoReplyStats",{params:{days:e}}),fetchAttachment:e=>t.get(`/ticket/attachment/${e}`,{responseType:"blob"})};export{d as M,m as S,l as t};
