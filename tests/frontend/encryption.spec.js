import { flushPromises, mount } from '@vue/test-utils';
import EncryptionDialog from '../../frontend/src/components/EncryptionDialog.vue';
import LocalDecryption from '../../frontend/src/components/LocalDecryption.vue';
import { transformFile } from '../../frontend/src/lib/fileEncryption.js';

vi.mock('../../frontend/src/lib/fileEncryption.js', () => ({ ENCRYPTION_FORMAT: 'WBENC001', transformFile: vi.fn() }));

function stubAnchorClicks() {
  const clicks = [];
  const spy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () { clicks.push(this); });
  return { clicks, spy };
}

describe('local encryption UI', () => {
  afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

  it('offers plain uploads only when optional and clears passwords on cancel', async () => {
    const optional = mount(EncryptionDialog);
    expect(optional.text()).toContain('Upload without encryption');
    const required = mount(EncryptionDialog, { props: { required: true } });
    expect(required.text()).not.toContain('Upload without encryption');
    await required.findAll('input')[0].setValue('long test password');
    await required.findAll('button').find((button) => button.text() === 'Cancel').trigger('click');
    expect(required.emitted('answer')[0][0]).toEqual({ choice: 'cancel', password: '' });
    expect(required.find('input').element.value).toBe('');
  });

  it('rejects mismatched passwords before emitting an upload choice', async () => {
    const wrapper = mount(EncryptionDialog);
    await wrapper.findAll('input')[0].setValue('long test password');
    await wrapper.findAll('input')[1].setValue('different password');
    await wrapper.find('form').trigger('submit');
    expect(wrapper.emitted('answer')).toBeUndefined();
    expect(wrapper.text()).toContain('do not match');
  });

  it('offers no plaintext download on authentication failure and permits retry without refetching', async () => {
    const createUrl = vi.fn(() => 'blob:verified');
    vi.stubGlobal('URL', { createObjectURL: createUrl, revokeObjectURL: vi.fn() });
    const fetcher = vi.fn(async () => ({ ok: true, blob: async () => new Blob(['ciphertext']) }));
    vi.stubGlobal('fetch', fetcher);
    const { clicks, spy } = stubAnchorClicks();
    transformFile.mockRejectedValueOnce(new Error('Incorrect password or damaged encrypted file.'));
    const wrapper = mount(LocalDecryption, { props: { url: '/download', name: 'test.txt' } });
    await wrapper.find('button').trigger('click');
    await wrapper.find('input').setValue('wrong');
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(createUrl).not.toHaveBeenCalled();
    expect(clicks).toHaveLength(0);
    transformFile.mockResolvedValueOnce(new Blob(['verified plaintext']));
    await wrapper.findAll('button').find((button) => button.text() === 'Retry password').trigger('click');
    await wrapper.find('input').setValue('correct');
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(clicks).toHaveLength(1);
    expect(clicks[0].getAttribute('download')).toBe('test.txt');
    expect(clicks[0].getAttribute('href')).toBe('blob:verified');
    expect(wrapper.find('.upload-queue-card').exists()).toBe(false);
    wrapper.unmount();
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:verified');
    spy.mockRestore();
  });

  it('offers a raw download that skips decryption entirely', async () => {
    const fetcher = vi.fn();
    vi.stubGlobal('fetch', fetcher);
    const { clicks, spy } = stubAnchorClicks();
    const wrapper = mount(LocalDecryption, { props: { url: '/download', name: 'vault.exe' } });
    await wrapper.find('button').trigger('click');
    const rawButton = wrapper.findAll('button').find((button) => button.text() === 'Download anyway');
    expect(rawButton).toBeTruthy();
    await rawButton.trigger('click');
    expect(fetcher).not.toHaveBeenCalled();
    expect(clicks).toHaveLength(1);
    expect(clicks[0].getAttribute('href')).toBe('/download');
    expect(wrapper.find('form').exists()).toBe(false);
    spy.mockRestore();
  });
});
