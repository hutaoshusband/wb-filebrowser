const languages = [
  ['rust', 'Rust', 'rs'], ['python', 'Python', 'py pyw pyi'],
  ['javascript', 'JavaScript', 'js mjs cjs jsx'], ['typescript', 'TypeScript', 'ts tsx mts cts'],
  ['php', 'PHP', 'php phtml'], ['java', 'Java', 'java'], ['c', 'C', 'c h'],
  ['cpp', 'C++', 'cpp cc cxx hpp hxx'], ['csharp', 'C#', 'cs'],
  ['go', 'Go', 'go'], ['ruby', 'Ruby', 'rb erb'], ['bash', 'Shell', 'sh bash zsh fish'],
  ['xml', 'HTML', 'html htm'], ['xml', 'XML', 'xml xsl xsd'],
  ['xml', 'Vue', 'vue'], ['xml', 'Svelte', 'svelte'],
  ['css', 'CSS', 'css'], ['scss', 'SCSS', 'scss sass'], ['less', 'Less', 'less'],
  ['json', 'JSON', 'json jsonc'], ['yaml', 'YAML', 'yml yaml'], ['sql', 'SQL', 'sql'],
  ['markdown', 'Markdown', 'md markdown'], ['swift', 'Swift', 'swift'],
  ['kotlin', 'Kotlin', 'kt kts'], ['lua', 'Lua', 'lua'], ['r', 'R', 'r'],
  ['ini', 'TOML', 'toml'], ['ini', 'Configuration', 'ini conf cfg env'],
];

export function sourceLanguage(item) {
  const extension = String(item?.extension || item?.name?.split('.').pop() || '').replace(/^\./, '').toLowerCase();
  const language = languages.find(([, , extensions]) => extensions.split(' ').includes(extension));
  return language ? { id: language[0], label: language[1] } : { id: 'plaintext', label: 'Plain text' };
}
