import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import CodePreview from '../../frontend/src/components/CodePreview.vue';

const item = { name: 'main.rs', extension: 'rs', mime_type: 'text/plain', file_icon_url: '/media/file-fallbacks/rust.svg' };
describe('code preview', () => {
  it('highlights Rust despite a plain text MIME type and shows its icon', async () => {
    const wrapper = mount(CodePreview, { props: { item, text: 'fn main() {\n    println!("hello");\n}\n' } });
    expect(wrapper.text()).toContain('Rust');
    await vi.waitFor(() => expect(wrapper.get('.hljs-keyword').text()).toBe('fn'));
    expect(wrapper.find('img').attributes('src')).toBe(item.file_icon_url);
    expect(wrapper.text()).toContain('4 lines');
    expect(wrapper.findAll('.cm-lineNumbers .cm-gutterElement').at(-1).text()).toBe('4');
    wrapper.unmount();
  });
  it.each([
    ['py', 'def greet():'], ['ts', 'const value: number = 2;'], ['go', 'package main'],
    ['sql', 'SELECT * FROM users;'], ['sh', 'if true; then echo hello; fi'],
    ['lua', 'local greeting = "hello"'], ['zig', 'const greeting = "hello";'],
    ['asm', '.global _start\n_start:'],
  ])('highlights %s files', async (extension, text) => {
    const wrapper = mount(CodePreview, { props: { item: { ...item, extension, name: `sample.${extension}` }, text } });
    await vi.waitFor(() => expect(wrapper.find('.cm-content span[class^="hljs-"]').exists()).toBe(true));
    wrapper.unmount();
  });
  it('renders untrusted markup as literal text', async () => {
    const text = '<img src=x onerror=alert(1)><script>alert(1)</script>';
    const wrapper = mount(CodePreview, { props: { item: { name: 'sample.html', extension: 'html' }, text } });
    await vi.waitFor(() => expect(wrapper.get('.cm-content').text()).toBe(text));
    expect(wrapper.find('.cm-content img, .cm-content script').exists()).toBe(false);
    wrapper.unmount();
  });
  it('keeps unknown text readable without guessing a language', async () => {
    const wrapper = mount(CodePreview, { props: { item: { name: 'notes.txt', extension: 'txt' }, text: 'ordinary words' } });
    expect(wrapper.text()).toContain('Plain text');
    await vi.waitFor(() => expect(wrapper.get('.cm-content').text()).toBe('ordinary words'));
    wrapper.unmount();
  });
});
