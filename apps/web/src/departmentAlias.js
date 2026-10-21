// Department aliases are stable authorization keys. Do not silently transliterate or lower-case.
export const normalizeDepartmentAlias = (value) => String(value ?? '').replace(/ +/g, '-');
export const isValidDepartmentAlias = (value) => value === '' || /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value);
