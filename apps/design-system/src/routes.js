import { catalogAnchorIds } from './catalogSections.js';
const aliases = { components:'buttons-actions',patterns:'tables-lists',history:'panels',confirmations:'statuses','service-dashboard':'navigation' };
export const routes = [
  { path:'/',name:'catalog',meta:{section:'foundations'} },
  ...catalogAnchorIds.map(id=>({ path:'/'+id,redirect:to=>({name:'catalog',query:to.query,hash:to.hash||'#section-'+id}) })),
  ...Object.entries(aliases).map(([legacy,anchor])=>({path:'/'+legacy,redirect:to=>({name:'catalog',query:to.query,hash:to.hash||'#section-'+anchor})})),
  { path:'/:pathMatch(.*)*',name:'not-found',meta:{section:'not-found'} },
];
