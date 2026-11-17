import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { EditorState, StateEffect } from '@codemirror/state';
import { EditorView, keymap, lineNumbers } from '@codemirror/view';
import { tags } from '@lezer/highlight';
import { loadCodeLanguage } from './codeLanguage.js';

const highlighting = HighlightStyle.define([
  { tag: [tags.keyword, tags.bool, tags.null], class: 'hljs-keyword' },
  { tag: [tags.string, tags.regexp, tags.inserted], class: 'hljs-string' },
  { tag: [tags.heading, tags.tagName, tags.propertyName, tags.attributeName, tags.definition(tags.variableName)], class: 'hljs-title' },
  { tag: [tags.typeName, tags.standard(tags.variableName), tags.number], class: 'hljs-number' },
  { tag: [tags.comment, tags.quote], class: 'hljs-comment' },
  { tag: [tags.meta, tags.variableName, tags.atom], class: 'hljs-meta' },
  { tag: tags.deleted, class: 'hljs-deletion' },
]);

export function mountCodeViewer(parent, text, language) {
  const view = new EditorView({
    parent,
    doc: text,
    extensions: [
      EditorState.readOnly.of(true),
      EditorView.contentAttributes.of({ 'aria-label': 'Source code' }),
      EditorState.tabSize.of(4),
      lineNumbers(),
      keymap.of([{
        key: 'Mod-a',
        run(view) {
          view.dispatch({ selection: { anchor: 0, head: view.state.doc.length } });
          return true;
        },
      }]),
      syntaxHighlighting(highlighting),
    ],
  });

  let disposed = false;
  if (language.id !== 'plaintext') {
    loadCodeLanguage(language).then((support) => {
      if (!disposed) view.dispatch({ effects: StateEffect.appendConfig.of(support) });
    }).catch(() => {
      // Keep the plain text view usable if an optional grammar cannot load.
    });
  }

  return {
    lineCount: view.state.doc.lines,
    destroy() {
      disposed = true;
      view.destroy();
    },
  };
}
