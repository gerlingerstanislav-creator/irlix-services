const colors = ['#448fd1','#19a180','#d49a31','#9465c8','#d66a82','#54aab6','#ba723a','#7286c7','#a39139','#ba67ae','#4d9c65','#b97865','#627bc0','#8e9d43','#aa6592','#379da1','#c88d46','#737cc1','#628b72','#bb6470'];
const identities = ['postgres','employees','timesheets','keycloak','cv-converter','rabbitmq','redis','clients','vacations','specialists','recruitment','equipment','dashboard','platform-core','migration','design-system','nginx'];
export const specialLabels = {'__other__':'Система / прочее','__free__':'Свободно'};
export function resourceColor(id) {
  if (id === '__other__') return 'var(--irlix-color-text-muted)';
  if (id === '__free__') return 'var(--irlix-color-border)';
  const index = identities.indexOf(id);
  if (index >= 0) return colors[index];
  const hash = [...id].reduce((n,c)=>(Math.imul(n,31)+c.charCodeAt(0))>>>0,0);
  return `hsl(${hash % 360} 48% 52%)`;
}
const number = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;

// Host and cgroup counters differ. Retain raw values in details; never invent free capacity.
export function resourceAllocation(services, field, total, used) {
  total = number(total); used = number(used);
  if (!total || used == null) return {total:total || 0,used,segments:[],unavailable:true,normalized:false,incomplete:true};
  used = Math.min(total,used);
  const values = services.map(service=>({id:service.id,value:number(service[field]),partial:!!service.partial || (field==='disk_bytes' && !!service.disk_partial)}));
  const sum = values.reduce((n,s)=>n+(s.value || 0),0);
  const normalized = sum > used;
  const factor = normalized && sum ? used/sum : 1;
  const segments = values.filter(s=>s.value>0).map(s=>({...s,size:s.value*factor,color:resourceColor(s.id)}));
  segments.push({id:'__other__',value:Math.max(0,used-sum),size:Math.max(0,used-sum),color:resourceColor('__other__')});
  segments.push({id:'__free__',value:total-used,size:total-used,color:resourceColor('__free__')});
  return {total,used,values,segments:segments.filter(s=>s.size>0),normalized,unavailable:false,incomplete:values.some(s=>s.value==null||s.partial)};
}
