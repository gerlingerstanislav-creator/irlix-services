export const routes = [
 { path: '/',name:'root',meta:{section:'resources'} },
 { path:'/resources/',name:'resources',meta:{section:'resources'} },
 { path:'/service-map/',name:'service-map',meta:{section:'service-map'} },
 { path:'/migration/control/',name:'migration-control',meta:{section:'migration-control'} },
 { path:'/migration/',redirect:{name:'migration-control'} },
 { path:'/:pathMatch(.*)*',name:'not-found',meta:{section:null} },
];
