<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Eye, EyeOff, LoaderCircle, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    courses: string[];
    currentYear: number;
    schoolYears: string[];
}>();

// ── Step management ───────────────────────────────────────────────────────────
const currentStep = ref(1);
const TOTAL_STEPS = 3;

const stepTitles = [
    'Personal Information',
    'Academic Information',
    'Documents & Account',
];

const canGoNext = computed(() => {
    if (currentStep.value === 1) {
        return (
            form.last_name &&
            form.first_name &&
            form.birthdate &&
            form.contact_number &&
            form.email
        );
    }
    if (currentStep.value === 2) {
        return (
            form.course &&
            form.year_level &&
            form.semester &&
            form.school_year &&
            form.student_type &&
            form.address_barangay &&
            form.address_city &&
            form.address_province
        );
    }
    return true;
});

const nextStep = () => {
    if (currentStep.value < TOTAL_STEPS) currentStep.value++;
};
const prevStep = () => {
    if (currentStep.value > 1) currentStep.value--;
};

// ── Password visibility ───────────────────────────────────────────────────────
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

// ── File input state ──────────────────────────────────────────────────────────
const validIdName = ref('');
const proofName = ref('');

const handleValidId = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) {
        form.valid_id = file;
        validIdName.value = file.name;
    }
};

const handleProof = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) {
        form.proof_of_enrollment = file;
        proofName.value = file.name;
    }
};

// ── Form ──────────────────────────────────────────────────────────────────────
const form = useForm({
    // Personal
    last_name:      '',
    first_name:     '',
    middle_name:    '',
    suffix:         '',
    gender:         '',
    birthdate:      '',
    civil_status:   '',
    contact_number: '',
    email:          '',
    // Address
    address_house:    '',
    address_street:   '',
    address_barangay: '',
    address_city:     'Sorsogon City',
    address_province: 'Sorsogon',
    address_zip:      '',
    // Academic
    existing_student_id: '',
    course:              '',
    year_level:          '',
    semester:            '',
    school_year:         '',
    student_type:        'new',
    // Guardian & Emergency
    guardian_name:     '',
    guardian_contact:  '',
    emergency_contact: '',
    // Documents
    valid_id:             null as File | null,
    proof_of_enrollment:  null as File | null,
    // Account
    password:              '',
    password_confirmation: '',
});

// Birthday year clamp
const clampBirthdayYear = (event: Event) => {
    const input = event.target as HTMLInputElement;
    if (!input.value) return;
    const parts = input.value.split('-');
    if (parts[0] && parts[0].length > 4) {
        parts[0] = parts[0].slice(0, 4);
        const clamped = parts.join('-');
        input.value = clamped;
        form.birthdate = clamped;
    }
};

const submit = () => {
    form.post(route('register.store'), {
        forceFormData: true,
        onError: (errors) => {
            // If errors are in step 1 fields, jump back to step 1
            const step1Fields = ['last_name', 'first_name', 'birthdate', 'contact_number', 'email', 'gender', 'civil_status'];
            const step2Fields = ['course', 'year_level', 'semester', 'school_year', 'student_type', 'address_barangay', 'address_city', 'address_province'];
            if (step1Fields.some(f => errors[f])) {
                currentStep.value = 1;
            } else if (step2Fields.some(f => errors[f])) {
                currentStep.value = 2;
            }
        },
    });
};

const studentTypeOptions = [
    { value: 'new',        label: 'New Student' },
    { value: 'old',        label: 'Old Student' },
    { value: 'transferee', label: 'Transferee' },
    { value: 'returnee',   label: 'Returnee' },
    { value: 'irregular',  label: 'Irregular' },
];

const selectClass = 'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <AuthBase
        title="Student Registration"
        description="Submit your registration for Accounting Department review"
        :wide="true"
    >
        <Head title="Register" />

        <!-- Progress indicator -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-medium text-muted-foreground">Step {{ currentStep }} of {{ TOTAL_STEPS }}</span>
                <span class="text-xs font-semibold text-foreground">{{ stepTitles[currentStep - 1] }}</span>
            </div>
            <div class="flex gap-1.5">
                <div
                    v-for="step in TOTAL_STEPS"
                    :key="step"
                    class="h-1.5 flex-1 rounded-full transition-colors duration-200"
                    :class="step <= currentStep ? 'bg-primary' : 'bg-muted'"
                />
            </div>
        </div>

        <!-- Pending registration notice -->
        <div
            v-if="form.errors.email && form.errors.email.includes('under review')"
            class="mb-4 rounded-md border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800"
        >
            <strong>Registration already submitted.</strong> Check your tracking token from your submission email.
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-5">

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- STEP 1: Personal Information                        -->
            <!-- ═══════════════════════════════════════════════════ -->
            <div v-show="currentStep === 1" class="grid gap-4">

                <!-- Name row -->
                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="last_name">Last Name <span class="text-destructive">*</span></Label>
                        <Input id="last_name" type="text" v-model="form.last_name" placeholder="Dela Cruz" autocomplete="family-name" />
                        <InputError :message="form.errors.last_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="first_name">First Name <span class="text-destructive">*</span></Label>
                        <Input id="first_name" type="text" v-model="form.first_name" placeholder="Juan" autocomplete="given-name" />
                        <InputError :message="form.errors.first_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="middle_name">Middle Name</Label>
                        <Input id="middle_name" type="text" v-model="form.middle_name" placeholder="Pedro" />
                        <InputError :message="form.errors.middle_name" />
                    </div>
                </div>

                <!-- Suffix + Gender + Civil Status -->
                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="suffix">Suffix</Label>
                        <select id="suffix" v-model="form.suffix" :class="selectClass">
                            <option value="">None</option>
                            <option>Jr.</option><option>Sr.</option>
                            <option>II</option><option>III</option><option>IV</option>
                        </select>
                        <InputError :message="form.errors.suffix" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="gender">Gender</Label>
                        <select id="gender" v-model="form.gender" :class="selectClass">
                            <option value="">Select</option>
                            <option>Male</option><option>Female</option>
                            <option>Other</option><option>Prefer not to say</option>
                        </select>
                        <InputError :message="form.errors.gender" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="civil_status">Civil Status</Label>
                        <select id="civil_status" v-model="form.civil_status" :class="selectClass">
                            <option value="">Select</option>
                            <option>Single</option><option>Married</option>
                            <option>Widowed</option><option>Separated</option>
                        </select>
                        <InputError :message="form.errors.civil_status" />
                    </div>
                </div>

                <!-- Birthdate + Contact -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="birthdate">Birthdate <span class="text-destructive">*</span></Label>
                        <Input
                            id="birthdate" type="date"
                            min="1900-01-01"
                            :max="new Date().toISOString().split('T')[0]"
                            v-model="form.birthdate"
                            @input="clampBirthdayYear"
                        />
                        <InputError :message="form.errors.birthdate" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="contact_number">Contact Number <span class="text-destructive">*</span></Label>
                        <Input id="contact_number" type="text" v-model="form.contact_number" placeholder="09171234567" />
                        <InputError :message="form.errors.contact_number" />
                    </div>
                </div>

                <!-- Email -->
                <div class="grid gap-1.5">
                    <Label for="email">Email Address <span class="text-destructive">*</span></Label>
                    <Input id="email" type="email" v-model="form.email" placeholder="juan@example.com" autocomplete="email" />
                    <InputError :message="form.errors.email" />
                </div>

                <!-- Info box -->
                <div class="rounded-md border border-blue-100 bg-blue-50 p-3 text-xs text-blue-700">
                    <strong>Note:</strong> Your registration will be reviewed by the Accounting Department before your account is activated.
                    You will receive an email notification once a decision is made.
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- STEP 2: Academic + Address                          -->
            <!-- ═══════════════════════════════════════════════════ -->
            <div v-show="currentStep === 2" class="grid gap-4">

                <!-- Student Type -->
                <div class="grid gap-1.5">
                    <Label>Student Type <span class="text-destructive">*</span></Label>
                    <div class="flex flex-wrap gap-3 pt-1">
                        <label
                            v-for="opt in studentTypeOptions"
                            :key="opt.value"
                            class="flex items-center gap-2 cursor-pointer"
                        >
                            <input
                                type="radio"
                                name="student_type"
                                :value="opt.value"
                                v-model="form.student_type"
                                class="accent-blue-600"
                            />
                            <span class="text-sm">{{ opt.label }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.student_type" />
                </div>

                <!-- Existing Student ID (conditional) -->
                <div v-if="['old', 'returnee', 'transferee'].includes(form.student_type)" class="grid gap-1.5">
                    <Label for="existing_student_id">Existing Student ID</Label>
                    <Input id="existing_student_id" type="text" v-model="form.existing_student_id" placeholder="e.g. 2023-0042" />
                    <p class="text-xs text-muted-foreground">Enter your previous student ID if you have one.</p>
                    <InputError :message="form.errors.existing_student_id" />
                </div>

                <!-- Course + Year Level -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="course">Course <span class="text-destructive">*</span></Label>
                        <select id="course" v-model="form.course" :class="selectClass">
                            <option value="">Select a course</option>
                            <option v-for="c in courses" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <InputError :message="form.errors.course" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="year_level">Year Level <span class="text-destructive">*</span></Label>
                        <select id="year_level" v-model="form.year_level" :class="selectClass">
                            <option value="">Select year level</option>
                            <option>1st Year</option><option>2nd Year</option>
                            <option>3rd Year</option><option>4th Year</option>
                        </select>
                        <InputError :message="form.errors.year_level" />
                    </div>
                </div>

                <!-- Semester + School Year -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="semester">Semester <span class="text-destructive">*</span></Label>
                        <select id="semester" v-model="form.semester" :class="selectClass">
                            <option value="">Select semester</option>
                            <option>1st Semester</option>
                            <option>2nd Semester</option>
                            <option>Summer</option>
                        </select>
                        <InputError :message="form.errors.semester" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="school_year">School Year <span class="text-destructive">*</span></Label>
                        <select id="school_year" v-model="form.school_year" :class="selectClass">
                            <option value="">Select school year</option>
                            <option v-for="sy in schoolYears" :key="sy" :value="sy">{{ sy }}</option>
                        </select>
                        <InputError :message="form.errors.school_year" />
                    </div>
                </div>

                <!-- Address -->
                <fieldset class="rounded-md border border-input p-3">
                    <legend class="px-1 text-xs font-semibold text-muted-foreground">Address</legend>
                    <div class="grid gap-3">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1.5">
                                <Label class="text-xs">House No. / Unit</Label>
                                <Input type="text" v-model="form.address_house" placeholder="Unit 4, Lot 12" />
                                <InputError :message="form.errors.address_house" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label class="text-xs">Street Name</Label>
                                <Input type="text" v-model="form.address_street" placeholder="Rizal Street" />
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="grid gap-1.5">
                                <Label class="text-xs">Barangay <span class="text-destructive">*</span></Label>
                                <Input type="text" v-model="form.address_barangay" placeholder="Cabid-an" />
                                <InputError :message="form.errors.address_barangay" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label class="text-xs">City / Municipality <span class="text-destructive">*</span></Label>
                                <Input type="text" v-model="form.address_city" placeholder="Sorsogon City" />
                                <InputError :message="form.errors.address_city" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label class="text-xs">Province <span class="text-destructive">*</span></Label>
                                <Input type="text" v-model="form.address_province" placeholder="Sorsogon" />
                                <InputError :message="form.errors.address_province" />
                            </div>
                        </div>
                        <div class="grid gap-1.5 max-w-[140px]">
                            <Label class="text-xs">ZIP Code</Label>
                            <Input type="text" v-model="form.address_zip" placeholder="4700" maxlength="10" />
                        </div>
                    </div>
                </fieldset>

                <!-- Guardian Information -->
                <fieldset class="rounded-md border border-input p-3">
                    <legend class="px-1 text-xs font-semibold text-muted-foreground">Guardian / Emergency Contact</legend>
                    <div class="grid gap-3">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1.5">
                                <Label class="text-xs">Guardian Name</Label>
                                <Input type="text" v-model="form.guardian_name" placeholder="Full name" />
                                <InputError :message="form.errors.guardian_name" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label class="text-xs">Guardian Contact No.</Label>
                                <Input type="text" v-model="form.guardian_contact" placeholder="09xxxxxxxxx" />
                                <InputError :message="form.errors.guardian_contact" />
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label class="text-xs">Emergency Contact (Name / Number)</Label>
                            <Input type="text" v-model="form.emergency_contact" placeholder="e.g. Maria Dela Cruz / 09171234567" />
                            <InputError :message="form.errors.emergency_contact" />
                        </div>
                    </div>
                </fieldset>
            </div>

            <!-- ═══════════════════════════════════════════════════ -->
            <!-- STEP 3: Documents & Account Setup                   -->
            <!-- ═══════════════════════════════════════════════════ -->
            <div v-show="currentStep === 3" class="grid gap-4">

                <!-- Document uploads -->
                <fieldset class="rounded-md border border-input p-3">
                    <legend class="px-1 text-xs font-semibold text-muted-foreground">Supporting Documents</legend>
                    <p class="text-xs text-muted-foreground mb-3">
                        Accepted formats: JPG, PNG, PDF. Max 5MB each. These help Accounting verify your identity.
                    </p>

                    <div class="grid gap-3">
                        <!-- Valid ID -->
                        <div class="grid gap-1.5">
                            <Label class="text-xs">Valid ID</Label>
                            <label
                                class="flex items-center gap-3 cursor-pointer rounded-md border border-dashed border-input bg-muted/30 px-4 py-3 text-sm hover:bg-muted/50 transition-colors"
                            >
                                <Upload class="h-4 w-4 text-muted-foreground flex-shrink-0" />
                                <span class="truncate text-muted-foreground">
                                    {{ validIdName || 'Click to upload valid ID (optional)' }}
                                </span>
                                <input
                                    type="file"
                                    class="sr-only"
                                    accept=".jpg,.jpeg,.png,.pdf"
                                    @change="handleValidId"
                                />
                            </label>
                            <InputError :message="form.errors.valid_id" />
                        </div>

                        <!-- Proof of Enrollment -->
                        <div class="grid gap-1.5">
                            <Label class="text-xs">Proof of Enrollment / Registration Form</Label>
                            <label
                                class="flex items-center gap-3 cursor-pointer rounded-md border border-dashed border-input bg-muted/30 px-4 py-3 text-sm hover:bg-muted/50 transition-colors"
                            >
                                <Upload class="h-4 w-4 text-muted-foreground flex-shrink-0" />
                                <span class="truncate text-muted-foreground">
                                    {{ proofName || 'Click to upload proof of enrollment (optional)' }}
                                </span>
                                <input
                                    type="file"
                                    class="sr-only"
                                    accept=".jpg,.jpeg,.png,.pdf"
                                    @change="handleProof"
                                />
                            </label>
                            <InputError :message="form.errors.proof_of_enrollment" />
                        </div>
                    </div>
                </fieldset>

                <!-- Password -->
                <div class="grid gap-1.5">
                    <Label for="password">Password <span class="text-destructive">*</span></Label>
                    <div class="relative">
                        <Input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            v-model="form.password"
                            placeholder="Min 8 characters"
                            autocomplete="new-password"
                            class="pr-10"
                        />
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        >
                            <EyeOff v-if="!showPassword" class="h-4 w-4" />
                            <Eye v-else class="h-4 w-4" />
                        </button>
                    </div>
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="password_confirmation">Confirm Password <span class="text-destructive">*</span></Label>
                    <div class="relative">
                        <Input
                            id="password_confirmation"
                            :type="showPasswordConfirmation ? 'text' : 'password'"
                            v-model="form.password_confirmation"
                            placeholder="Repeat password"
                            autocomplete="new-password"
                            class="pr-10"
                        />
                        <button
                            type="button"
                            @click="showPasswordConfirmation = !showPasswordConfirmation"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        >
                            <EyeOff v-if="!showPasswordConfirmation" class="h-4 w-4" />
                            <Eye v-else class="h-4 w-4" />
                        </button>
                    </div>
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <!-- Summary -->
                <div class="rounded-md border border-muted bg-muted/30 p-3 text-xs text-muted-foreground space-y-1">
                    <p class="font-semibold text-foreground">Review before submitting:</p>
                    <p><span class="font-medium">Name:</span> {{ form.last_name }}, {{ form.first_name }} {{ form.middle_name }}</p>
                    <p><span class="font-medium">Email:</span> {{ form.email }}</p>
                    <p><span class="font-medium">Course:</span> {{ form.course }} — {{ form.year_level }}</p>
                    <p><span class="font-medium">Semester:</span> {{ form.semester }}, {{ form.school_year }}</p>
                    <p><span class="font-medium">Type:</span> {{ studentTypeOptions.find(o => o.value === form.student_type)?.label }}</p>
                </div>

                <!-- General error -->
                <InputError :message="form.errors.email" v-if="!form.errors.email?.includes('under review')" />
            </div>

            <!-- ── Navigation buttons ──────────────────────────── -->
            <div class="flex items-center justify-between mt-2">
                <Button
                    v-if="currentStep > 1"
                    type="button"
                    variant="outline"
                    @click="prevStep"
                    class="gap-1.5"
                >
                    <ChevronLeft class="h-4 w-4" /> Back
                </Button>
                <div v-else />

                <Button
                    v-if="currentStep < TOTAL_STEPS"
                    type="button"
                    @click="nextStep"
                    :disabled="!canGoNext"
                    class="gap-1.5"
                >
                    Next <ChevronRight class="h-4 w-4" />
                </Button>

                <Button
                    v-else
                    type="submit"
                    :disabled="form.processing"
                    class="gap-1.5"
                >
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Submit Registration
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink :href="route('login')" class="underline underline-offset-4">Log in</TextLink>
            </div>
        </form>
    </AuthBase>
</template>