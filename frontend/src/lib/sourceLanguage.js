const languages = [
  ['rust', 'Rust', 'rs'], ['python', 'Python', 'py pyw pyi'],
  ['javascript', 'JavaScript', 'js mjs cjs jsx'], ['typescript', 'TypeScript', 'ts tsx mts cts'],
  ['php', 'PHP', 'php phtml'], ['java', 'Java', 'java'], ['c', 'C', 'c h'],
  ['cpp', 'C++', 'cpp cc cxx hpp hxx'], ['csharp', 'C#', 'cs'],
  ['go', 'Go', 'go'], ['ruby', 'Ruby', 'rb erb'], ['bash', 'Shell', 'sh bash zsh fish'],
  ['html', 'HTML', 'html htm'], ['xml', 'XML', 'xml xsl xsd'],
  ['html', 'Vue', 'vue'], ['html', 'Svelte', 'svelte'],
  ['css', 'CSS', 'css'], ['scss', 'SCSS', 'scss'], ['sass', 'Sass', 'sass'], ['less', 'Less', 'less'],
  ['json', 'JSON', 'json jsonc'], ['yaml', 'YAML', 'yml yaml'], ['sql', 'SQL', 'sql'],
  ['markdown', 'Markdown', 'md markdown'], ['swift', 'Swift', 'swift'],
  ['kotlin', 'Kotlin', 'kt kts'], ['lua', 'Lua', 'lua'], ['r', 'R', 'r'],
  ['toml', 'TOML', 'toml'], ['ini', 'Configuration', 'ini conf cfg env'],
  ['asm', 'Assembly', 'asm s inc'], ['z80', 'Z80 Assembly', 'z80'],
  ['zig', 'Zig', 'zig zon'], ['clojure', 'Clojure', 'clj cljs cljc edn'],
  ['cmake', 'CMake', 'cmake'], ['diff', 'Diff', 'diff patch'],
  ['erlang', 'Erlang', 'erl hrl'], ['fortran', 'Fortran', 'f f77 f90 f95 f03 f08 for'],
  ['haskell', 'Haskell', 'hs'], ['julia', 'Julia', 'jl'], ['pascal', 'Pascal', 'pas pp'],
  ['perl', 'Perl', 'pl pm'], ['powershell', 'PowerShell', 'ps1 psm1 psd1'],
  ['protobuf', 'Protocol Buffers', 'proto'], ['scheme', 'Scheme', 'scm ss'],
  ['verilog', 'Verilog', 'v vh sv svh'], ['vhdl', 'VHDL', 'vhd vhdl'],
  ['wast', 'WebAssembly Text', 'wat wast'], ['d', 'D', 'd'],
];

export function sourceLanguage(item) {
  const extension = String(item?.extension || item?.name?.split('.').pop() || '').replace(/^\./, '').toLowerCase();
  const language = languages.find(([, , extensions]) => extensions.split(' ').includes(extension));
  return { id: language?.[0] ?? 'plaintext', label: language?.[1] ?? 'Plain text', extension };
}
