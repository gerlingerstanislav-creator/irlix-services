<script setup>
defineProps({ level: { type: String, default: '' } });
const titles = {
  red: 'Запланировано отсутствие в ближайший месяц',
  yellow: 'Запланировано отсутствие в ближайшие три месяца',
};
</script>

<template>
  <span v-if="titles[level]" class="attempt-absence-alert" :class="'attempt-absence-alert--' + level"
    role="img" :aria-label="titles[level]" :title="titles[level]">!</span>
</template>

<style scoped>
.attempt-absence-alert {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  flex:none;
  width:17px;
  height:17px;
  border-radius:50%;
  font-size:12px;
  font-weight:800;
  line-height:1;
  vertical-align:middle;
}
.attempt-absence-alert--red { background:#fce6e5; color:#b42318; border:1px solid #e34842; }
.attempt-absence-alert--yellow { background:#fff1c7; color:#8f5f00; border:1px solid #cf990d; }
</style>
