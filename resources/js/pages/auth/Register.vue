<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    courses: string[];
}>();

const step = ref(1);
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const addressParts = ref({
    house_lot_unit: '',
    street_name: '',
    barangay: '',
    municipality_city: '',
    province: 'Sorsogon',
});

const form = useForm({
    last_name: '',
    first_name: '',
    middle_initial: '',
    birthday: '',
    email: '',
    year_level: '',
    course: '',
    is_irregular: false,
    address_house_lot_unit: '',
    address_street_name: '',
    address_barangay: '',
    address_municipality_city: '',
    address_province: 'Sorsogon',
    phone: '',
    password: '',
    password_confirmation: '',
});

const nextStep = () => { step.value = 2; };

/**
 * Clamps the year portion of a date input value to exactly 4 digits.
 * Browsers allow users to type beyond 4 digits in the year segment of
 * type="date" inputs, producing values like "20251-06-15". This handler
 * intercepts that and truncates the year back to 4 characters.
 */
const clampBirthdayYear = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const value = input.value; // format: YYYY-MM-DD

    if (!value) return;

    const parts = value.split('-');
    // parts[0] is the year segment; only act if it exceeds 4 digits
    if (parts[0] && parts[0].length > 4) {
        parts[0] = parts[0].slice(0, 4);
        const clamped = parts.join('-');
        input.value = clamped;
        form.birthday = clamped;
    }
};

const submit = () => {
    form.post(route('register.store'), {
        onFinish: () => {
            if (!Object.keys(form.errors).length) {
                form.reset('password', 'password_confirmation');
            }
        },
    });
};
</script>

<template>
    <AuthBase title="Create an account" description="Enter your details below to create your account" :wide="true">
        <Head title="Register" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-4">

                <!-- Row 1: Last Name, First Name, Middle Initial -->
                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-2">
                        <Label for="last_name">Last Name</Label>
                        <Input id="last_name" type="text" required v-model="form.last_name" placeholder="Dela Cruz" autocomplete="family-name" />
                        <InputError :message="form.errors.last_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="first_name">First Name</Label>
                        <Input id="first_name" type="text" required v-model="form.first_name" placeholder="Juan" autocomplete="given-name" />
                        <InputError :message="form.errors.first_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="middle_initial">M.I.</Label>
                        <Input id="middle_initial" type="text" v-model="form.middle_initial" placeholder="M." autocomplete="additional-name" />
                        <InputError :message="form.errors.middle_initial" />
                    </div>
                </div>

                <!-- Row 2: Birthday, Phone -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="birthday">Birthday</Label>
                        <Input
                            id="birthday"
                            type="date"
                            required
                            min="1900-01-01"
                            :max="new Date().toISOString().split('T')[0]"
                            v-model="form.birthday"
                            @input="clampBirthdayYear"
                        />
                        <InputError :message="form.errors.birthday" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="phone">Phone Number</Label>
                        <Input id="phone" type="text" required v-model="form.phone" placeholder="09171234567" />
                        <InputError :message="form.errors.phone" />
                    </div>
                </div>

                <!-- Row 3: Email -->
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input id="email" type="email" required autocomplete="email" v-model="form.email" placeholder="email@example.com" />
                    <InputError :message="form.errors.email" />
                </div>

                <!-- Row 4: Year Level, Course -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="year_level">Year Level</Label>
                        <select id="year_level" v-model="form.year_level" required
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2">
                            <option value="">Select Year Level</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                        <InputError :message="form.errors.year_level" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="course">Course</Label>
                        <select id="course" v-model="form.course" required
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2">
                            <option value="">Select a course</option>
                            <option v-for="course in courses" :key="course" :value="course">{{ course }}</option>
                        </select>
                        <InputError :message="form.errors.course" />
                    </div>
                </div>

                <!-- Row 4b: Student Type -->
                <div class="grid gap-2">
                    <Label>Student Type</Label>
                    <div class="flex gap-4 mt-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="is_irregular" :value="false" v-model="form.is_irregular" class="accent-blue-600" />
                            <span class="text-sm font-medium text-gray-700">Regular</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="is_irregular" :value="true" v-model="form.is_irregular" class="accent-amber-500" />
                            <span class="text-sm font-medium text-amber-700">Irregular</span>
                        </label>
                    </div>
                    <p class="text-xs text-gray-400">Regular students follow the standard curriculum. Irregular students have custom unit loads.</p>
                </div>

                <!-- Row 5: Address -->
                <div class="grid gap-2">
                    <Label>Address</Label>
                    <div class="grid gap-2 rounded-md border border-input p-3">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <Input type="text" required v-model="form.address_house_lot_unit" placeholder="e.g. Unit 4, Lot 12" />
                                <InputError :message="form.errors.address_house_lot_unit" />
                            </div>
                            <div>
                                <Input type="text" v-model="form.address_street_name" placeholder="e.g. Rizal Street" />
                                <InputError :message="form.errors.address_street_name" />
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <Input type="text" required v-model="form.address_barangay" placeholder="e.g. Barangay Cabid-an" />
                                <InputError :message="form.errors.address_barangay" />
                            </div>
                            <div>
                                <Input type="text" required v-model="form.address_municipality_city" placeholder="e.g. Sorsogon City" />
                                <InputError :message="form.errors.address_municipality_city" />
                            </div>
                            <div>
                                <Input type="text" required v-model="form.address_province" placeholder="e.g. Sorsogon" />
                                <InputError :message="form.errors.address_province" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 6: Password, Confirm Password -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="password">Password</Label>
                        <div class="relative">
                            <Input id="password" :type="showPassword ? 'text' : 'password'" required minlength="8"
                                autocomplete="new-password" v-model="form.password"
                                placeholder="Min 8 characters" class="pr-10" />
                            <button type="button" @click="showPassword = !showPassword"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <EyeOff v-if="!showPassword" :size="16" /><Eye v-else :size="16" />
                            </button>
                        </div>
                        <InputError :message="form.errors.password" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password_confirmation">Confirm Password</Label>
                        <div class="relative">
                            <Input id="password_confirmation" :type="showPasswordConfirmation ? 'text' : 'password'" required
                                autocomplete="new-password" v-model="form.password_confirmation"
                                placeholder="Confirm password" class="pr-10" />
                            <button type="button" @click="showPasswordConfirmation = !showPasswordConfirmation"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <EyeOff v-if="!showPasswordConfirmation" :size="16" /><Eye v-else :size="16" />
                            </button>
                        </div>
                        <InputError :message="form.errors.password_confirmation" />
                    </div>
                </div>

                <!-- Submit -->
                <Button type="submit" class="mt-2 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Create account
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink :href="route('login')" class="underline underline-offset-4">Log in</TextLink>
            </div>
        </form>
    </AuthBase>
</template>