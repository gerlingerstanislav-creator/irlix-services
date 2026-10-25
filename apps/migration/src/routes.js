// Legacy standalone Migration UI; the public entry currently redirects to System.
export const routes = [
  { path:'/',name:'root',meta:{section:'control'} },
  { path:'/control/',name:'control',meta:{section:'control'} },
  { path:'/console/',redirect:{name:'control'} },
  { path:'/:pathMatch(.*)*',name:'not-found',meta:{section:'not-found'} },
];
