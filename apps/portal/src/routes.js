export const routes = [
 { path:'/',name:'dashboard',meta:{section:'dashboard'} },
 { path:'/tasks/',name:'tasks',meta:{section:'tasks'} },
 { path:'/:pathMatch(.*)*',name:'not-found',meta:{section:'not-found'} },
];
