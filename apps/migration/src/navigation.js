export const migrationNavigation = [
  {id:'control',label:'Контроль переноса',icon:'database',href:'/migration/control/'},
];
export function navigateMigration(section){
  const item=migrationNavigation.find(x=>x.id===section);
  if(item&&location.pathname!==item.href)location.assign(item.href+location.search+location.hash);
}
