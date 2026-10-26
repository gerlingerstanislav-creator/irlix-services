// Match parts of a Russian full name regardless of casing or extra spaces.
export const matchesEmployeeName = (fullName, query) => {
  const normalize = (value) => String(value ?? '')
    .normalize('NFKC')
    .toLocaleLowerCase('ru-RU')
    .trim()
    .replace(/\s+/g, ' ');
  const terms = normalize(query).split(' ').filter(Boolean);
  if (!terms.length) return true;
  const name = normalize(fullName);
  return terms.every((term) => name.includes(term));
};
