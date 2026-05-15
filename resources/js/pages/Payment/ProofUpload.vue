<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import { Head, useForm } from '@inertiajs/vue3';
import { UploadCloud, File, CheckCircle, AlertCircle, ShieldCheck, ShieldX, Loader2 } from 'lucide-vue-next';
import { ref, computed } from 'vue';

const { formatCurrency, formatDate } = useDataFormatting();

const props = defineProps<{
    transaction: {
        id: number;
        amount: number;
        payment_method: string;
        term_name: string;
        description: string | null;
        created_at: string;
    };
}>();

const breadcrumbs = [
    { title: 'My Account', href: route('student.account') },
    { title: 'Upload Proof of Payment' },
];

const form = useForm({
    proof_of_payment: null as File | null,
});

const fileInput = ref<HTMLInputElement | null>(null);
const fileName = ref<string | null>(null);
const fileSize = ref<number | null>(null);

// ─── AI Validation State ───────────────────────────────────────────────────
const aiValidating = ref(false);
const aiResult = ref<'valid' | 'invalid' | 'uncertain' | null>(null);
const aiMessage = ref<string | null>(null);

/**
 * Convert a File to base64 string (strips the data URI prefix).
 */
const fileToBase64 = (file: File): Promise<string> =>
    new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            const result = reader.result as string;
            resolve(result.split(',')[1]);
        };
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });

/**
 * Send the selected image/PDF to Claude via the Anthropic API and check
 * whether it looks like a legitimate payment receipt or proof of payment.
 *
 * PDFs are validated by file type heuristic only (Claude vision cannot read
 * PDF binary), so we give them a pass with a soft warning.
 */
const validateWithAI = async (file: File): Promise<void> => {
    aiValidating.value = true;
    aiResult.value = null;
    aiMessage.value = null;

    // PDFs: Claude vision can't read binary PDF — allow but warn
    if (file.type === 'application/pdf') {
        await new Promise((r) => setTimeout(r, 400)); // brief pause for UX
        aiResult.value = 'uncertain';
        aiMessage.value =
            'PDF detected. Please make sure this is a valid payment receipt or bank slip. Our team will verify it manually.';
        aiValidating.value = false;
        return;
    }

    try {
        const base64 = await fileToBase64(file);
        const mediaType = file.type as 'image/jpeg' | 'image/png' | 'image/webp';

        const response = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: 'claude-sonnet-4-20250514',
                max_tokens: 1000,
                messages: [
                    {
                        role: 'user',
                        content: [
                            {
                                type: 'image',
                                source: { type: 'base64', media_type: mediaType, data: base64 },
                            },
                            {
                                type: 'text',
                                text: `You are a payment verification assistant for a school payment system.

Analyze this image and determine if it is a legitimate proof of payment or payment receipt.

A valid proof of payment typically shows one or more of:
- Bank transfer confirmation or transaction receipt
- GCash / Maya / e-wallet transfer confirmation
- Official OR (Official Receipt) from a school or business
- Payment slip with amount, date, and reference number
- Bank deposit slip
- Credit/debit card transaction receipt

An INVALID submission would be:
- Anime or cartoon characters
- Memes, screenshots of social media, or unrelated photos
- Selfies, food photos, or any non-payment image
- Blank images or test images

Respond ONLY in this JSON format with no extra text:
{"result": "valid" | "invalid" | "uncertain", "reason": "brief one-sentence explanation"}

- "valid" = clearly a payment receipt/proof
- "invalid" = clearly NOT a payment receipt (e.g. anime, meme, random photo)  
- "uncertain" = could be a receipt but hard to tell (blurry, partial, etc.)`,
                            },
                        ],
                    },
                ],
            }),
        });

        const data = await response.json();
        const raw = data.content?.find((b: any) => b.type === 'text')?.text ?? '';

        let parsed: { result: string; reason: string } | null = null;
        try {
            // Strip possible markdown fences
            const clean = raw.replace(/```json|```/g, '').trim();
            parsed = JSON.parse(clean);
        } catch {
            // If JSON parse fails, treat as uncertain
        }

        if (parsed?.result === 'valid') {
            aiResult.value = 'valid';
            aiMessage.value = parsed.reason ?? 'Image looks like a valid payment receipt.';
        } else if (parsed?.result === 'invalid') {
            aiResult.value = 'invalid';
            aiMessage.value =
                parsed.reason ??
                'This image does not appear to be a payment receipt. Please upload the correct file.';
            // Clear the file so the user must re-select
            form.errors.proof_of_payment = aiMessage.value as any;
        } else {
            aiResult.value = 'uncertain';
            aiMessage.value =
                parsed?.reason ?? 'Could not fully verify this image. Make sure it clearly shows payment details.';
        }
    } catch {
        // Network error or API unavailable — allow submission but note it
        aiResult.value = 'uncertain';
        aiMessage.value = 'Automatic verification unavailable. Our team will review your submission manually.';
    } finally {
        aiValidating.value = false;
    }
};

const handleFileSelect = async (e: Event) => {
    const target = e.target as HTMLInputElement;
    const file = target.files?.[0];

    if (file) {
        form.proof_of_payment = file;
        fileName.value = file.name;
        fileSize.value = file.size;
        form.errors.proof_of_payment = '';
        // Reset previous AI result
        aiResult.value = null;
        aiMessage.value = null;
        // Run AI validation immediately after selection
        await validateWithAI(file);
    }
};

const handleDrop = async (e: DragEvent) => {
    e.preventDefault();
    e.stopPropagation();

    const file = e.dataTransfer?.files?.[0];
    if (file) {
        const input = fileInput.value;
        if (input) {
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            input.files = dataTransfer.files;
            await handleFileSelect({ target: input } as any);
        }
    }
};

const submit = () => {
    if (!form.proof_of_payment) {
        form.errors.proof_of_payment = ['Please select a file to upload.'];
        return;
    }
    if (aiResult.value === 'invalid') {
        form.errors.proof_of_payment = 'Please upload a valid proof of payment, not a random image.';
        return;
    }

    form.post(route('payment.proof.upload', props.transaction.id), {
        preserveScroll: true,
        forceFormData: true,
    });
};

const isValidFile = computed(() => {
    if (!form.proof_of_payment) return false;
    const validTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    const maxSize = 5120 * 1024; // 5MB
    return validTypes.includes(form.proof_of_payment.type) && form.proof_of_payment.size <= maxSize;
});

const canSubmit = computed(() =>
    isValidFile.value &&
    !form.processing &&
    !aiValidating.value &&
    aiResult.value !== 'invalid',
);
</script>

<template>
    <AppLayout>
        <Head title="Upload Proof of Payment" />

        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Upload Proof of Payment</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Upload a receipt or proof of your payment to complete the submission.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Left: Upload Form -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Payment Summary Card -->
                    <div class="ccdi-card p-6 space-y-4">
                        <h2 class="text-base font-semibold text-gray-900 border-b pb-3">
                            Payment Summary
                        </h2>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Amount</span>
                                <span class="font-semibold">{{ formatCurrency(transaction.amount) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Payment Method</span>
                                <span class="font-semibold capitalize">{{ transaction.payment_method }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Payment Term</span>
                                <span class="font-semibold">{{ transaction.term_name }}</span>
                            </div>
                            <div v-if="transaction.description" class="flex justify-between">
                                <span class="text-gray-600">Notes</span>
                                <span class="text-right">{{ transaction.description }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- File Upload Section -->
                    <div class="ccdi-card p-6 space-y-5">
                        <h2 class="text-base font-semibold text-gray-900 border-b pb-3">
                            Upload Receipt
                        </h2>

                        <!-- Drag and Drop Area -->
                        <div
                            @drop="handleDrop"
                            @dragover.prevent
                            class="rounded-xl border-2 border-dashed border-gray-300 hover:border-indigo-400 p-8 text-center transition-colors cursor-pointer"
                            :class="{
                                'border-indigo-400 bg-indigo-50': fileName,
                            }"
                        >
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                @change="handleFileSelect"
                                class="hidden"
                            />

                            <div class="space-y-3">
                                <div class="flex justify-center">
                                    <div
                                        class="p-3 rounded-full"
                                        :class="fileName ? 'bg-green-100' : 'bg-gray-100'"
                                    >
                                        <UploadCloud
                                            :size="32"
                                            :class="fileName ? 'text-green-600' : 'text-gray-400'"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <p class="font-semibold text-gray-900">
                                        {{ fileName ? 'File Selected' : 'Drag and drop your receipt here' }}
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        or
                                        <button
                                            type="button"
                                            @click="fileInput?.click()"
                                            class="text-indigo-600 hover:underline font-medium"
                                        >
                                            browse your files
                                        </button>
                                    </p>
                                </div>

                                <p class="text-xs text-gray-400">
                                    PDF, JPG, PNG, or WebP • Max 5 MB
                                </p>
                            </div>
                        </div>

                        <!-- Selected File Details -->
                        <div v-if="fileName" class="flex items-center gap-3 p-4 bg-green-50 rounded-lg border border-green-200">
                            <File :size="20" class="text-green-600 flex-shrink-0" />
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-green-900 truncate">{{ fileName }}</p>
                                <p class="text-xs text-green-700">
                                    {{ fileSize ? (fileSize / 1024).toFixed(1) : 0 }} KB
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="() => { fileName = null; fileSize = null; form.proof_of_payment = null; }"
                                class="text-green-600 hover:text-green-700 font-medium"
                            >
                                Remove
                            </button>
                        </div>

                        <!-- AI Validation: Loading -->
                        <div v-if="aiValidating" class="flex items-center gap-3 p-4 bg-indigo-50 rounded-lg border border-indigo-200">
                            <Loader2 :size="18" class="text-indigo-600 flex-shrink-0 animate-spin" />
                            <p class="text-sm text-indigo-700 font-medium">Verifying your file, please wait…</p>
                        </div>

                        <!-- AI Validation: Valid -->
                        <div v-else-if="aiResult === 'valid'" class="flex items-start gap-3 p-4 bg-green-50 rounded-lg border border-green-200">
                            <ShieldCheck :size="18" class="text-green-600 flex-shrink-0 mt-0.5" />
                            <p class="text-sm text-green-800">
                                <strong>Verification passed.</strong> {{ aiMessage }}
                            </p>
                        </div>

                        <!-- AI Validation: Invalid -->
                        <div v-else-if="aiResult === 'invalid'" class="flex items-start gap-3 p-4 bg-red-50 rounded-lg border border-red-200">
                            <ShieldX :size="18" class="text-red-600 flex-shrink-0 mt-0.5" />
                            <div class="text-sm text-red-800">
                                <p class="font-semibold">Invalid file detected.</p>
                                <p class="mt-1">{{ aiMessage }}</p>
                                <p class="mt-2 font-medium">Please upload your actual payment receipt or bank transfer confirmation.</p>
                            </div>
                        </div>

                        <!-- AI Validation: Uncertain -->
                        <div v-else-if="aiResult === 'uncertain'" class="flex items-start gap-3 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                            <AlertCircle :size="18" class="text-yellow-600 flex-shrink-0 mt-0.5" />
                            <p class="text-sm text-yellow-800">
                                <strong>Note:</strong> {{ aiMessage }}
                            </p>
                        </div>

                        <!-- Validation Error -->
                        <div v-if="form.errors.proof_of_payment" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                            {{ form.errors.proof_of_payment }}
                        </div>

                        <!-- Info Message -->
                        <div class="flex items-start gap-3 p-4 bg-blue-50 rounded-lg border border-blue-200">
                            <AlertCircle :size="18" class="text-blue-600 flex-shrink-0 mt-0.5" />
                            <p class="text-sm text-blue-700">
                                <strong>Make sure your receipt shows:</strong>
                                <br />
                                • Date and time of payment
                                <br />
                                • Amount (₱{{ formatCurrency(transaction.amount) }})
                                <br />
                                • Your name (if visible)
                                <br />
                                • Reference or transaction number
                            </p>
                        </div>

                        <!-- Submit Button -->
                        <button
                            type="button"
                            @click="submit"
                            :disabled="!canSubmit"
                            class="w-full rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white shadow transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span v-if="form.processing">Uploading…</span>
                            <span v-else-if="aiValidating">Checking file…</span>
                            <span v-else-if="aiResult === 'invalid'">Invalid File — Please Re-upload</span>
                            <span v-else>Submit for Verification</span>
                        </button>
                    </div>
                </div>

                <!-- Right: Info Panel -->
                <div class="space-y-4">
                    <!-- What Happens Next -->
                    <div class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-4">
                            What's Next
                        </h3>
                        <div class="space-y-4">
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center h-8 w-8 rounded-full bg-green-100">
                                        <CheckCircle :size="18" class="text-green-600" />
                                    </div>
                                </div>
                                <div>
                                    <p class="font-medium text-sm text-gray-900">Upload Receipt</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Done! You're on this step now.</p>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center h-8 w-8 rounded-full bg-blue-100">
                                        <span class="text-xs font-semibold text-blue-600">2</span>
                                    </div>
                                </div>
                                <div>
                                    <p class="font-medium text-sm text-gray-900">Awaiting Verification</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Accounting staff will review your receipt.</p>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center h-8 w-8 rounded-full bg-gray-100">
                                        <span class="text-xs font-semibold text-gray-600">3</span>
                                    </div>
                                </div>
                                <div>
                                    <p class="font-medium text-sm text-gray-900">Payment Approved</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Balance updated once verified.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tips Card -->
                    <div class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-3">
                            Tips
                        </h3>
                        <ul class="space-y-2 text-xs text-gray-600">
                            <li>✓ Take a clear, well-lit photo</li>
                            <li>✓ Make sure all text is readable</li>
                            <li>✓ Include the full receipt/proof</li>
                            <li>✓ File must be under 5 MB</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>