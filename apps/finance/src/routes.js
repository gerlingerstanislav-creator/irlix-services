// Finance owns its financial pages. The entry route is selected by permissions during bootstrap.
export const routes = [
  { path: '/', name:'root', meta:{section:'overview'} },
  ...['overview','revenue','pnl','profitability','invoices','expenses','cash-flow','planning','settings','permissions']
    .map(name=>({path:'/'+name+'/',name,meta:{section:name}})),
  { path:'/:pathMatch(.*)*', name:'not-found',meta:{section:'not-found'} },
];
