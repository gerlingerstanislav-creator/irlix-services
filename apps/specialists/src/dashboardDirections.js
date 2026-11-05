/** View-only dashboard filtering over previously authorized Employees roster. */
export function validDashboardDepartmentSelection(departments, selection) {
  if (!Array.isArray(selection)) return [];
  const allowed=new Set((departments||[]).map(item=>String(item.id)));
  return [...new Set(selection.map(String).filter(id=>allowed.has(id)))];
}
export function scopedDashboardPeople(people, departments, selection) {
  const chosen=validDashboardDepartmentSelection(departments,selection);
  if (!chosen.length) return people||[];
  const selected=new Set(chosen);
  let changed=true;
  while(changed){
    changed=false;
    for(const department of departments||[]){
      const id=String(department.id);
      if(!selected.has(id)&&department.parent_id!=null&&selected.has(String(department.parent_id))){
        selected.add(id);changed=true;
      }
    }
  }
  return (people||[]).filter(person=>selected.has(String(person.department_id)));
}
