import{e as h}from"../index.js";/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const k=[["path",{d:"M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8",key:"mg9rjx"}]],T=h("bold",k);/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const y=[["rect",{width:"18",height:"18",x:"3",y:"3",rx:"2",ry:"2",key:"1m3agn"}],["circle",{cx:"9",cy:"9",r:"2",key:"af1f0g"}],["path",{d:"m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21",key:"1xmnt7"}]],F=h("image",y);/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const _=[["line",{x1:"19",x2:"10",y1:"4",y2:"4",key:"15jd3p"}],["line",{x1:"14",x2:"5",y1:"20",y2:"20",key:"bu0au3"}],["line",{x1:"15",x2:"9",y1:"4",y2:"20",key:"uljnxc"}]],W=h("italic",_);/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const $=[["path",{d:"M11 5h10",key:"1cz7ny"}],["path",{d:"M11 12h10",key:"1438ji"}],["path",{d:"M11 19h10",key:"11t30w"}],["path",{d:"M4 4h1v5",key:"10yrso"}],["path",{d:"M4 9h2",key:"r1h2o0"}],["path",{d:"M6.5 20H3.4c0-1 2.6-1.925 2.6-3.5a1.5 1.5 0 0 0-2.6-1.02",key:"xtkcd5"}]],q=h("list-ordered",$);/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const S=[["path",{d:"M3 5h.01",key:"18ugdj"}],["path",{d:"M3 12h.01",key:"nlz23k"}],["path",{d:"M3 19h.01",key:"noohij"}],["path",{d:"M8 5h13",key:"1pao27"}],["path",{d:"M8 12h13",key:"1za7za"}],["path",{d:"M8 19h13",key:"m83p4d"}]],O=h("list",S);/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const v=[["path",{d:"M21 7v6h-6",key:"3ptur4"}],["path",{d:"M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7",key:"1kgawr"}]],V=h("redo",v);/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const A=[["path",{d:"M3 7v6h6",key:"1v2h90"}],["path",{d:"M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13",key:"1r6uu6"}]],G=h("undo",A),m="<!--xboard-md:",g="-->";function M(r){return String(r||"").replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/'/g,"&#39;").replace(/</g,"&lt;").replace(/>/g,"&gt;")}function d(r){return String(r||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;")}function x(r){try{return btoa(unescape(encodeURIComponent(r)))}catch{return""}}function E(r){try{return decodeURIComponent(escape(atob(r)))}catch{return""}}function I(r){const e=r||"";if(e.indexOf(m)!==0)return null;const o=e.indexOf(g,m.length);if(o<0)return null;const s=e.slice(m.length,o).trim();if(!s)return null;const t=E(s);if(!t)return null;const l=e.slice(o+g.length).trimStart();return{markdown:t,html:l}}function L(r){const e=I(r||"");return e?e.html:r||""}function f(r){const a=String(r||"").replace(/\r\n?/g,`
`).split(`
`),o=[],s=l=>{let i=d(l);return i=i.replace(/`([^`]+)`/g,(c,u)=>`<code>${u}</code>`),i=i.replace(/!\[([^\]]*)\]\(([^)]+)\)/g,(c,u,n)=>`<img src="${n}" alt="${u}" />`),i=i.replace(/\[([^\]]+)\]\(([^)]+)\)/g,(c,u,n)=>`<a href="${n}">${u}</a>`),i=i.replace(/\*\*([^*]+)\*\*/g,"<strong>$1</strong>"),i=i.replace(/__([^_]+)__/g,"<strong>$1</strong>"),i=i.replace(/(^|[^*])\*([^*]+)\*(?!\*)/g,"$1<em>$2</em>"),i=i.replace(/(^|[^_])_([^_]+)_(?!_)/g,"$1<em>$2</em>"),i};let t=0;for(;t<a.length;){const l=a[t],i=l.trim();if(i.startsWith("```")){const n=i.slice(3).trim();t+=1;const p=[];for(;t<a.length&&!a[t].trim().startsWith("```");)p.push(a[t]),t+=1;t<a.length&&(t+=1);const w=d(p.join(`
`));o.push(`<pre><code${n?` class="language-${M(n)}"`:""}>${w}</code></pre>`);continue}const c=l.match(/^(#{1,6})\s+(.*)$/);if(c){const n=c[1].length;o.push(`<h${n}>${s(c[2].trim())}</h${n}>`),t+=1;continue}if(/^([-*_])\1\1+\s*$/.test(i)){o.push("<hr />"),t+=1;continue}if(/^\s*>\s?/.test(l)){const n=[];for(;t<a.length&&/^\s*>\s?/.test(a[t]);)n.push(a[t].replace(/^\s*>\s?/,"")),t+=1;const p=f(n.join(`
`));o.push(`<blockquote>${p}</blockquote>`);continue}if(/^\s*([-*])\s+/.test(l)){const n=[];for(;t<a.length&&/^\s*([-*])\s+/.test(a[t]);)n.push(a[t].replace(/^\s*([-*])\s+/,"")),t+=1;o.push(`<ul>${n.map(p=>`<li>${s(p)}</li>`).join("")}</ul>`);continue}if(/^\s*\d+\.\s+/.test(l)){const n=[];for(;t<a.length&&/^\s*\d+\.\s+/.test(a[t]);)n.push(a[t].replace(/^\s*\d+\.\s+/,"")),t+=1;o.push(`<ol>${n.map(p=>`<li>${s(p)}</li>`).join("")}</ol>`);continue}if(!i){t+=1;continue}const u=[];for(;t<a.length;){const n=a[t],p=n.trim();if(!p||p.startsWith("```")||/^(#{1,6})\s+/.test(n)||/^\s*>\s?/.test(n)||/^\s*([-*])\s+/.test(n)||/^\s*\d+\.\s+/.test(n)||/^([-*_])\1\1+\s*$/.test(p))break;u.push(n),t+=1}o.push(`<p>${u.map(n=>s(n)).join("<br />")}</p>`)}return o.join(`
`)}function Z(r){let e=L(r||"");return e=e.replace(/\r\n?/g,`
`),e=e.replace(/<\s*br\s*\/?>/gi,`
`),e=e.replace(/<\s*(strong|b)[^>]*>/gi,"**"),e=e.replace(/<\/\s*(strong|b)\s*>/gi,"**"),e=e.replace(/<\s*(em|i)[^>]*>/gi,"*"),e=e.replace(/<\/\s*(em|i)\s*>/gi,"*"),e=e.replace(/<\s*a[^>]*href\s*=\s*["']([^"']+)["'][^>]*>([\s\S]*?)<\/\s*a\s*>/gi,(a,o,s)=>`[${String(s||"").replace(/<[^>]+>/g,"")}](${o})`),e=e.replace(/<\s*img[^>]*>/gi,a=>{var t,l;const o=((t=a.match(/\bsrc\s*=\s*["']([^"']+)["']/i))==null?void 0:t[1])||"",s=((l=a.match(/\balt\s*=\s*["']([^"']*)["']/i))==null?void 0:l[1])||"";return o?`![${s}](${o})`:""}),e=e.replace(/<\s*li[^>]*>/gi,"- "),e=e.replace(/<\/\s*li\s*>/gi,`
`),e=e.replace(/<\/\s*(ul|ol)\s*>/gi,`
`),e=e.replace(/<\s*(ul|ol)[^>]*>/gi,""),e=e.replace(/<\s*pre[^>]*>\s*<\s*code[^>]*>/gi,"```\n"),e=e.replace(/<\/\s*code\s*>\s*<\/\s*pre\s*>/gi,"\n```\n\n"),e=e.replace(/<\/\s*p\s*>/gi,`

`),e=e.replace(/<\s*p[^>]*>/gi,""),e=e.replace(/<\/\s*h([1-6])\s*>/gi,`

`),e=e.replace(/<\s*h([1-6])[^>]*>/gi,(a,o)=>`${"#".repeat(Number(o))} `),e=e.replace(/<[^>]+>/g,""),e=e.replace(/\n{3,}/g,`

`).trim(),e}function X(r){const e=String(r||""),a=x(e),o=f(e);return a?`${m}${a}${g}
${o}`:o}function j(r){let e=r||"";return e=e.replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi,""),e=e.replace(/<(iframe|object|embed|link|style|svg|math)[\s\S]*?>[\s\S]*?<\/\1>/gi,""),e=e.replace(/\son\w+="[^"]*"/gi,""),e=e.replace(/\son\w+='[^']*'/gi,""),e=e.replace(/\son\w+=([^\s>]+)/gi,""),e=e.replace(/javascript:/gi,""),e}const N=new Set(["script","style","iframe","object","embed","link","meta","base","form","input","button","textarea","select","option","svg","math"]),z=new Set(["a","b","blockquote","br","code","div","em","h1","h2","h3","h4","h5","h6","hr","i","img","li","ol","p","pre","s","span","strong","sub","sup","table","tbody","td","th","thead","tr","u","ul"]),C={a:new Set(["href","title","target","rel","class"]),code:new Set(["class"]),div:new Set(["class"]),img:new Set(["src","alt","title","width","height","class"]),ol:new Set(["start","class"]),p:new Set(["class"]),pre:new Set(["class"]),span:new Set(["class"]),table:new Set(["class"]),td:new Set(["colspan","rowspan","class"]),th:new Set(["colspan","rowspan","scope","class"]),tr:new Set(["class"]),ul:new Set(["class"])};function P(r){return String(r||"").replace(/[\u0000-\u0020\u007f]+/g,"").trim()}function D(r){return String(r||"").split(/\s+/).map(a=>a.trim()).filter(a=>/^[a-zA-Z0-9:_-]+$/.test(a)).join(" ")}function H(r){const e=Number.parseInt(String(r||"").trim(),10);return!Number.isFinite(e)||e<=0?null:String(e)}function U(r,e,a){const o=String(a||"").trim();if(!o)return null;const s=P(o);if(!s)return null;const t=s.match(/^([a-zA-Z][a-zA-Z\d+.-]*):/);if(!t)return o;const l=t[1].toLowerCase();return l==="http"||l==="https"||e==="href"&&(l==="mailto"||l==="tel")||r==="img"&&e==="src"&&/^data:image\/(?:png|gif|jpe?g|webp|avif|bmp);base64,[a-z0-9+/=]+$/i.test(s)?o:null}function B(r){const e=r.parentNode;if(!e){r.remove();return}for(;r.firstChild;)e.insertBefore(r.firstChild,r);e.removeChild(r)}function b(r){if(typeof DOMParser>"u"||typeof document>"u")return j(r);const a=new DOMParser().parseFromString(r||"","text/html"),o=Array.from(a.body.querySelectorAll("*"));for(const s of o){if(!s.isConnected)continue;const t=s.tagName.toLowerCase();if(N.has(t)){s.remove();continue}if(!z.has(t)){B(s);continue}const l=C[t]||new Set;for(const i of Array.from(s.attributes)){const c=i.name.toLowerCase(),u=i.value;if(c.startsWith("on")||c==="style"||c==="srcset"||c==="formaction"){s.removeAttribute(i.name);continue}if(!l.has(c)){s.removeAttribute(i.name);continue}if(c==="href"||c==="src"){const n=U(t,c,u);n?s.setAttribute(i.name,n):s.removeAttribute(i.name);continue}if(c==="target"){String(u||"").trim().toLowerCase()==="_blank"?s.setAttribute("target","_blank"):s.removeAttribute(i.name);continue}if(c==="rel"){s.removeAttribute(i.name);continue}if(c==="class"){const n=D(u);n?s.setAttribute(i.name,n):s.removeAttribute(i.name);continue}if(c==="width"||c==="height"||c==="colspan"||c==="rowspan"||c==="start"){const n=H(u);n?s.setAttribute(i.name,n):s.removeAttribute(i.name);continue}if(c==="scope"){const n=String(u||"").trim().toLowerCase();n==="col"||n==="row"||n==="colgroup"||n==="rowgroup"?s.setAttribute(i.name,n):s.removeAttribute(i.name)}}t==="a"&&(s.getAttribute("href")?s.getAttribute("target")==="_blank"&&s.setAttribute("rel","noopener noreferrer"):s.removeAttribute("target")),t==="img"&&!s.getAttribute("src")&&s.remove()}return a.body.innerHTML}function K(r){return b(r)}function Y(r){return b(r)}export{T as B,W as I,O as L,V as R,G as U,q as a,F as b,L as c,Y as d,M as e,I as f,X as g,Z as h,f as m,K as s};
