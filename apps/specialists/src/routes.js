// Professional profiles are owned by Specialists, not the platform service catalog.
export const routes = [
  { path:'/', name:'dashboard', meta:{section:'dashboard'} },
  { path:'/people', name:'people', meta:{section:'people'} },
  { path:'/competencies', name:'competencies', meta:{section:'competencies'} },
  { path:'/role-permissions', name:'role-permissions', meta:{section:'role-permissions'} },
  { path:'/people/:employeeId(\\d+)', name:'person', meta:{section:'person'} },
  { path:'/:pathMatch(.*)*', name:'not-found', meta:{section:'not-found'} },
];
