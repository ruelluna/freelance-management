<?php

use App\Models\Label;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

new #[Layout('layouts::app')] #[Title('Labels')] class extends Component {
    use Interactions;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|size:6|regex:/^[0-9a-fA-F]{6}$/')]
    public string $color = 'ededed';

    public function mount(): void
    {
        Gate::authorize('viewAny', [Label::class, $this->team()]);
    }

    public function create(): void
    {
        Gate::authorize('create', [Label::class, $this->team()]);

        $validated = $this->validate();

        $this->team()->labels()->firstOrCreate(
            ['name' => $validated['name']],
            ['color' => strtolower($validated['color'])],
        );

        $this->reset('name');
        $this->color = 'ededed';

        unset($this->labels);

        $this->toast()->success(__('Label saved.'))->send();
    }

    public function delete(string $labelId): void
    {
        $label = $this->team()->labels()->findOrFail($labelId);

        Gate::authorize('delete', $label);

        $label->delete();

        $this->toast()->success(__('Label deleted.'))->send();
    }

    /**
     * @return Collection<int, Label>
     */
    #[Computed]
    public function labels(): Collection
    {
        return $this->team()->labels()->withCount('issues')->orderBy('name')->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('create', [Label::class, $this->team()]);
    }

    protected function team(): Team
    {
        return Auth::user()->currentTeam;
    }
}; ?>

<div class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ __('Labels') }}</h1>
            <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('Tags you can attach to tasks. Use a project when the work belongs together.') }}</p>
        </div>

        @if ($this->canManage)
            <x-card>
                <form wire:submit="create" class="space-y-4">
                    <x-input wire:model="name" :label="__('Name')" data-test="label-name" />

                    <div x-data="labelColorWheel()" class="space-y-2">
                        <x-label :label="__('Color')" />

                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                            <div class="relative size-[180px] shrink-0">
                                <canvas
                                    x-ref="wheel"
                                    width="180"
                                    height="180"
                                    class="size-[180px] cursor-crosshair touch-none rounded-full"
                                    data-test="label-color-wheel"
                                    role="application"
                                    aria-label="{{ __('Color wheel') }}"
                                    x-on:pointerdown.prevent="start($event)"
                                    x-on:pointermove.window="move($event)"
                                    x-on:pointerup.window="stop()"
                                    x-on:pointercancel.window="stop()"
                                ></canvas>
                                <span
                                    class="pointer-events-none absolute size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow ring-1 ring-black/40"
                                    x-bind:style="thumbStyle"
                                ></span>
                            </div>

                            <div class="flex w-full max-w-xs flex-col gap-3">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="size-10 shrink-0 rounded-lg border border-gray-200 dark:border-dark-600"
                                        x-bind:style="swatchStyle"
                                        data-test="label-color-preview"
                                    ></span>
                                    <span class="font-mono text-sm text-gray-700 dark:text-dark-200" data-test="label-color-value" x-text="display"></span>
                                </div>

                                <div>
                                    <x-label :label="__('Brightness')" />
                                    <input
                                        type="range"
                                        min="0"
                                        max="100"
                                        step="1"
                                        x-bind:value="brightness"
                                        x-on:input="setBrightness($event.target.value)"
                                        data-test="label-brightness"
                                        class="h-2 w-full cursor-pointer appearance-none rounded-full bg-zinc-300 accent-blue-500 dark:bg-zinc-600 [&::-moz-range-thumb]:size-4 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-blue-500 [&::-webkit-slider-thumb]:size-4 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-blue-500"
                                    />
                                </div>
                            </div>
                        </div>

                        <x-error property="color" />
                    </div>

                    <x-button submit data-test="create-label" :text="__('Add label')" />
                </form>
            </x-card>
        @endif

        <div class="space-y-2">
            @forelse ($this->labels as $label)
                <x-card wire:key="label-{{ $label->id }}">
                    <div class="flex items-center justify-between gap-4" data-test="label-row">
                        <div class="flex items-center gap-3">
                            <span class="size-4 rounded-full" style="background-color: #{{ $label->color }}"></span>
                            <div>
                                <a href="{{ route('issues.index', ['labelId' => $label->id]) }}" class="font-medium hover:underline" wire:navigate>
                                    {{ $label->name }}
                                </a>
                                <p class="text-sm text-gray-500 dark:text-dark-300">{{ trans_choice(':count issue|:count issues', $label->issues_count) }}</p>
                            </div>
                        </div>

                        @if ($this->canManage)
                            <x-button outline sm wire:click="delete('{{ $label->id }}')" data-test="delete-label" :text="__('Delete')" />
                        @endif
                    </div>
                </x-card>
            @empty
                <p class="text-sm text-gray-500 dark:text-dark-300">{{ __('No labels yet. They appear when issues sync, or you can add one here.') }}</p>
            @endforelse
        </div>
    </div>

    @script
        <script>
            (() => {
                const register = () => {
                    Alpine.data('labelColorWheel', () => ({
                        size: 180,
                        hue: 0,
                        sat: 0,
                        val: 0.93,
                        brightness: 93,
                        dragging: false,
                        hex: 'ededed',
                        init() {
                            this.hex = this.$wire.color || 'ededed';
                            this.applyHex(this.hex);
                            this.$nextTick(() => this.draw());
                            this.$watch('$wire.color', (value) => {
                                if (String(value).toLowerCase() === this.toHex()) {
                                    return;
                                }

                                this.hex = String(value || 'ededed');
                                this.applyHex(this.hex);
                                this.draw();
                            });
                        },
                        get display() {
                            return '#' + this.hex;
                        },
                        get thumbStyle() {
                            const radius = this.size / 2;
                            const angle = (this.hue * Math.PI) / 180;
                            const x = radius + Math.cos(angle) * this.sat * (radius - 1);
                            const y = radius + Math.sin(angle) * this.sat * (radius - 1);

                            return `left:${x}px;top:${y}px;background-color:#${this.hex}`;
                        },
                        get swatchStyle() {
                            return `background-color:#${this.hex}`;
                        },
                        start(event) {
                            this.dragging = true;
                            this.pick(event);
                        },
                        move(event) {
                            if (! this.dragging) {
                                return;
                            }

                            this.pick(event);
                        },
                        stop() {
                            this.dragging = false;
                        },
                        pick(event) {
                            const rect = this.$refs.wheel.getBoundingClientRect();
                            const x = event.clientX - rect.left;
                            const y = event.clientY - rect.top;
                            const cx = rect.width / 2;
                            const cy = rect.height / 2;
                            const dx = x - cx;
                            const dy = y - cy;
                            const radius = Math.min(cx, cy);
                            const dist = Math.min(Math.hypot(dx, dy), radius);
                            let hue = (Math.atan2(dy, dx) * 180) / Math.PI;

                            if (hue < 0) {
                                hue += 360;
                            }

                            this.hue = hue;
                            this.sat = radius === 0 ? 0 : dist / radius;
                            this.commit();
                        },
                        setBrightness(value) {
                            this.brightness = Number(value);
                            this.val = this.brightness / 100;
                            this.draw();
                            this.commit();
                        },
                        commit() {
                            this.hex = this.toHex();
                            this.$wire.set('color', this.hex);
                        },
                        applyHex(value) {
                            const hex = String(value || 'ededed').replace('#', '').toLowerCase();

                            if (! /^[0-9a-f]{6}$/.test(hex)) {
                                return;
                            }

                            const red = Number.parseInt(hex.slice(0, 2), 16);
                            const green = Number.parseInt(hex.slice(2, 4), 16);
                            const blue = Number.parseInt(hex.slice(4, 6), 16);
                            const [hue, saturation, bright] = this.rgbToHsv(red, green, blue);

                            this.hue = hue;
                            this.sat = saturation;
                            this.val = bright;
                            this.brightness = Math.round(bright * 100);
                        },
                        toHex() {
                            return this.hsvToRgb(this.hue, this.sat, this.val)
                                .map((channel) => channel.toString(16).padStart(2, '0'))
                                .join('');
                        },
                        hsvToRgb(hue, saturation, value) {
                            const chroma = value * saturation;
                            const match = chroma * (1 - Math.abs(((hue / 60) % 2) - 1));
                            const offset = value - chroma;
                            let red = 0;
                            let green = 0;
                            let blue = 0;

                            if (hue < 60) {
                                red = chroma;
                                green = match;
                            } else if (hue < 120) {
                                red = match;
                                green = chroma;
                            } else if (hue < 180) {
                                green = chroma;
                                blue = match;
                            } else if (hue < 240) {
                                green = match;
                                blue = chroma;
                            } else if (hue < 300) {
                                red = match;
                                blue = chroma;
                            } else {
                                red = chroma;
                                blue = match;
                            }

                            return [red, green, blue].map((channel) => Math.round((channel + offset) * 255));
                        },
                        rgbToHsv(red, green, blue) {
                            const r = red / 255;
                            const g = green / 255;
                            const b = blue / 255;
                            const max = Math.max(r, g, b);
                            const min = Math.min(r, g, b);
                            const delta = max - min;
                            let hue = 0;

                            if (delta !== 0) {
                                if (max === r) {
                                    hue = (g - b) / delta + (g < b ? 6 : 0);
                                } else if (max === g) {
                                    hue = (b - r) / delta + 2;
                                } else {
                                    hue = (r - g) / delta + 4;
                                }

                                hue *= 60;
                            }

                            return [hue, max === 0 ? 0 : delta / max, max];
                        },
                        draw() {
                            const canvas = this.$refs.wheel;

                            if (! canvas) {
                                return;
                            }

                            const dpr = Math.min(window.devicePixelRatio || 1, 2);
                            const bitmap = Math.round(this.size * dpr);
                            const center = bitmap / 2;
                            const radius = bitmap / 2 - dpr;

                            canvas.width = bitmap;
                            canvas.height = bitmap;

                            const context = canvas.getContext('2d');
                            const image = context.createImageData(bitmap, bitmap);
                            const pixels = image.data;

                            for (let y = 0; y < bitmap; y++) {
                                for (let x = 0; x < bitmap; x++) {
                                    const dx = x + 0.5 - center;
                                    const dy = y + 0.5 - center;
                                    const distance = Math.hypot(dx, dy);
                                    const index = (y * bitmap + x) * 4;

                                    if (distance > radius + 1) {
                                        pixels[index + 3] = 0;
                                        continue;
                                    }

                                    let hue = (Math.atan2(dy, dx) * 180) / Math.PI;

                                    if (hue < 0) {
                                        hue += 360;
                                    }

                                    const [red, green, blue] = this.hsvToRgb(hue, Math.min(distance / radius, 1), this.val);

                                    pixels[index] = red;
                                    pixels[index + 1] = green;
                                    pixels[index + 2] = blue;
                                    pixels[index + 3] = distance > radius ? Math.round(255 * (1 - (distance - radius))) : 255;
                                }
                            }

                            context.putImageData(image, 0, 0);
                        },
                    }));
                };

                if (window.Alpine) {
                    register();
                } else {
                    document.addEventListener('alpine:init', register);
                }
            })();
        </script>
    @endscript
