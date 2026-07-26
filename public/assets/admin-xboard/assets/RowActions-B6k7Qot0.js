import{j as e}from"./vendor-react-Dp_bvMCs.js";import{b as o,B as d,c as r}from"./index.js";import{D as p,a as u,b as x,d as m,e as h}from"./dropdown-menu-BUiq3WX0.js";/**
 * @license lucide-react v0.561.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const j=[["circle",{cx:"12",cy:"12",r:"1",key:"41hilf"}],["circle",{cx:"19",cy:"12",r:"1",key:"1wjl8i"}],["circle",{cx:"5",cy:"12",r:"1",key:"1pcz8c"}]],w=o("ellipsis",j);function N({label:c,actions:t,disabled:i,className:a,contentClassName:l}){return e.jsxs(p,{children:[e.jsx(u,{asChild:!0,children:e.jsx(d,{size:"icon",variant:"ghost",className:r("h-8 w-8",a),"aria-label":c,title:c,disabled:i,children:e.jsx(w,{className:"h-4 w-4"})})}),e.jsx(x,{align:"end",className:r("w-[220px]",l),children:t.map((s,n)=>s.type==="separator"?e.jsx(m,{},`separator-${n}`):e.jsxs(h,{onSelect:s.onSelect,disabled:s.disabled,className:r(s.destructive&&"text-destructive focus:text-destructive"),children:[s.icon?e.jsx("span",{className:"mr-2 inline-flex h-4 w-4 items-center justify-center",children:s.icon}):null,s.label]},n))})]})}export{N as R};
