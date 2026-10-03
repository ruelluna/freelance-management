<?php

use App\Models\EditorImage;
use App\Models\Team;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Modelable;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    #[Modelable]
    public string $content = '';

    public ?UploadedFile $image = null;

    public ?string $label = null;

    public string $minHeight = '8rem';

    public ?string $testId = null;

    public function storeImage(): string
    {
        $this->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ]);

        $upload = $this->image;
        $user = auth()->user();
        $team = $user?->currentTeam;

        abort_unless($upload !== null && $user !== null && $team instanceof Team && $user->belongsToTeam($team), 403);

        $path = $upload->store('editor-images/'.$team->id, 'local');

        abort_unless(is_string($path), 500);

        $mime = $upload->getMimeType();

        abort_unless(is_string($mime) && in_array($mime, EditorImage::MIMES, true), 422);

        $editorImage = EditorImage::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $mime,
        ]);

        $this->reset('image');

        return $editorImage->url();
    }
};
?>

<div>
    <div
        class="relative"
        data-test="markdown-editor-uploads"
        x-data="markdownEditorUploads"
        x-on:dragenter.prevent="dragEnter($event)"
        x-on:dragover.prevent="dragOver($event)"
        x-on:dragleave="dragLeave($event)"
        x-on:drop.prevent="dropImage($event)"
        x-on:paste.capture="pasteImage($event)"
    >
        <x-editor
            markdown
            wire:model="content"
            upload-property="image"
            upload-method="storeImage"
            :upload-mimes="\App\Models\EditorImage::MIMES"
            :upload-max-size="5120"
            :label="$label"
            :min-height="$minHeight"
            data-test="{{ $testId }}"
        />

        <div
            x-show="dragging"
            x-cloak
            class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-lg border-2 border-dashed border-blue-500 bg-blue-500/10 text-sm font-medium text-blue-700 dark:text-blue-300"
        >
            {{ __('Drop image to upload') }}
        </div>

        <p x-show="uploading" x-cloak class="mt-2 text-sm text-gray-500 dark:text-dark-300">{{ __('Uploading image...') }}</p>
        <p x-show="error" x-text="error" x-cloak class="mt-2 text-sm text-red-600 dark:text-red-400"></p>
    </div>
</div>

@script
<script>
    Alpine.data('markdownEditorUploads', () => ({
        dragging: false,
        uploading: false,
        error: '',
        messages: {
            type: @js(__('Use a PNG, JPG, GIF, or WebP image.')),
            size: @js(__('Image must be 5 MB or smaller.')),
            failed: @js(__('Upload failed. Please try again.')),
        },
        allowed: @js(\App\Models\EditorImage::MIMES),
        hasFiles(transfer) {
            return [...(transfer?.types || [])].includes('Files')
        },
        isImage(file) {
            if (! file) {
                return false
            }

            if (this.allowed.includes(file.type) || file.type === 'image/jpg') {
                return true
            }

            return /\.(png|jpe?g|gif|webp)$/i.test(file.name || '')
        },
        imageFiles(data) {
            const files = [...(data?.files || [])].filter((file) => this.isImage(file))

            if (files.length > 0) {
                return files
            }

            return [...(data?.items || [])]
                .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
                .map((item) => item.getAsFile())
                .filter((file) => this.isImage(file))
        },
        dragEnter(event) {
            if (! this.hasFiles(event.dataTransfer)) {
                return
            }

            this.dragging = true
        },
        dragLeave(event) {
            if (event.currentTarget.contains(event.relatedTarget)) {
                return
            }

            this.dragging = false
        },
        dragOver(event) {
            if (! this.hasFiles(event.dataTransfer)) {
                return
            }

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy'
            }
        },
        dropImage(event) {
            this.dragging = false

            const files = [...(event.dataTransfer?.files || [])]

            if (files.length === 0) {
                return
            }

            this.accept(files, { x: event.clientX, y: event.clientY })
        },
        pasteImage(event) {
            const files = this.imageFiles(event.clipboardData)

            if (files.length === 0) {
                return
            }

            event.preventDefault()
            event.stopPropagation()
            this.accept(files)
        },
        async accept(files, point = null) {
            const images = []
            let rejection = ''

            for (const file of files) {
                if (! this.isImage(file)) {
                    rejection = this.messages.type
                    continue
                }

                if (file.size > 5120 * 1024) {
                    rejection = this.messages.size
                    continue
                }

                images.push(file)
            }

            for (const file of images) {
                await this.upload(file, point)
                point = null
            }

            if (rejection !== '' && this.error === '') {
                this.error = rejection
            }
        },
        upload(file, point = null) {
            this.uploading = true
            this.error = ''

            return new Promise((resolve) => {
                this.$wire.upload('image', file, async () => {
                    try {
                        const url = await this.$wire.call('storeImage')

                        if (typeof url === 'string' && url !== '') {
                            this.insert(url, point)
                        } else {
                            this.error = this.messages.failed
                        }
                    } catch (e) {
                        this.error = this.messages.failed
                    } finally {
                        this.uploading = false
                        resolve()
                    }
                }, () => {
                    this.uploading = false
                    this.error = this.messages.failed
                    resolve()
                })
            })
        },
        insert(url, point = null) {
            const editable = this.$root.querySelector('[contenteditable="true"]')

            if (! editable) {
                return
            }

            editable.focus()
            this.placeCaret(editable, point)
            document.execCommand('insertHTML', false, `<img src="${this.escapeAttr(url)}" alt="" />`)
            editable.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertHTML' }))
        },
        placeCaret(editable, point) {
            const selection = window.getSelection()

            if (! selection) {
                return
            }

            if (point && typeof document.caretRangeFromPoint === 'function') {
                const range = document.caretRangeFromPoint(point.x, point.y)

                if (range && editable.contains(range.startContainer)) {
                    selection.removeAllRanges()
                    selection.addRange(range)

                    return
                }
            }

            if (selection.rangeCount > 0 && editable.contains(selection.anchorNode)) {
                return
            }

            const range = document.createRange()
            range.selectNodeContents(editable)
            range.collapse(false)
            selection.removeAllRanges()
            selection.addRange(range)
        },
        escapeAttr(value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('"', '&quot;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
        },
    }))
</script>
@endscript
