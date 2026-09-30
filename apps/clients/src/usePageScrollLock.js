import { onBeforeUnmount, watch } from 'vue';
export function usePageScrollLock(active) {
  let restore;
  watch(active, locked=>{
    if (locked && !restore) {
      const elements=[document.documentElement,document.body,...document.querySelectorAll('.clients-app .content')];
      const previous=elements.map(element=>[element,element.style.overflow]);
      elements.forEach(element=>{element.style.overflow='hidden'});
      restore=()=>previous.forEach(([element,overflow])=>{element.style.overflow=overflow});
    } else if (!locked && restore) {restore();restore=null;}
  },{immediate:true,flush:'post'});
  onBeforeUnmount(()=>restore?.());
}
