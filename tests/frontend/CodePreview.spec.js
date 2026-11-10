import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CodePreview from '../../frontend/src/components/CodePreview.vue';

const item = { name: 'main.rs', extension: 'rs', mime_type: 'text/plain', file_icon_url: '/media/file-fallbacks/rust.svg' };
describe('code preview', () => {
  it('highlights Rust despite a plain text MIME type and shows its icon', () => {
    const wrapper = mount(CodePreview, { props: { item, text: 'fn main() {\n    println!("hello");\n}\n' } });
    expect(wrapper.text()).toContain('Rust');
    expect(wrapper.find('.hljs-keyword').text()).toBe('fn');
    expect(wrapper.find('img').attributes('src')).toBe(item.file_icon_url);
    expect(wrapper.find('.code-view__numbers').text()).toBe('1\n2\n3\n4');
  });
  it.each([['py', 'def greet():'], ['ts', 'const value: number = 2;'], ['go', 'package main'], ['sql', 'SELECT * FROM users;'], ['sh', 'if true; then echo hello; fi']])('highlights %s files', (extension, text) => {
    const wrapper = mount(CodePreview, { props: { item: { ...item, extension, name: `sample.${extension}` }, text } });
    expect(wrapper.find('code span').exists()).toBe(true);
  });
  it('renders untrusted markup as literal text', () => {
    const text = '<img src=x onerror=alert(1)><script>alert(1)</script>';
    const wrapper = mount(CodePreview, { props: { item: { name: 'sample.html', extension: 'html' }, text } });
    expect(wrapper.find('code').text()).toBe(text);
    expect(wrapper.find('code img, code script').exists()).toBe(false);
  });
  it('keeps unknown text readable without guessing a language', () => {
    const wrapper = mount(CodePreview, { props: { item: { name: 'notes.txt', extension: 'txt' }, text: 'ordinary words' } });
    expect(wrapper.text()).toContain('Plain text');
    expect(wrapper.find('code').text()).toBe('ordinary words');
  });
});
