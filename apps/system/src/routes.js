export const routes = [
 { path: '/',name:'root',meta:{section:'resources'} },
 { path:'/resources/',name:'resources',meta:{section:'resources'} },
 { path:'/reports/',name:'reports',meta:{section:'reports'} },
 { path:'/service-map/',name:'service-map',meta:{section:'service-map'} },
 { path:'/migration/control/',name:'migration-control',meta:{section:'migration-control'} },
 { path:'/migration/',redirect:to=>({name:'migration-control',query:to.query,hash:to.hash}) },
 // The historical standalone /migration/console/ host redirect lands here.
 { path:'/migration/console/',redirect:to=>({name:'migration-control',query:to.query,hash:to.hash}) },
 { path:'/:pathMatch(.*)*',name:'not-found',meta:{section:null} },
];
