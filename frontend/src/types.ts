export type User = {
  id: number
  name: string
  email: string
  role: string
  role_label: string
  can_manage_staff: boolean
  branch: { id: number; name: string; city: string | null } | null
}

export type Staff = {
  id: number
  name: string
  email: string
  role: string
  gender: string | null
  phone: string | null
  department: string | null
  signature_text: string | null
  is_active: boolean
}

export type RoleOption = { value: string; label: string }

export type Patient = {
  id: number
  uhid: string
  name: string
  gender: string
  date_of_birth: string
  dob_estimated: boolean
  age: number
  mobile: string
  mobile_alt: string | null
  email: string | null
  blood_group: string | null
  preferred_language: string | null
  address: string | null
  city: string | null
  pincode: string | null
  care_of: string | null
  occupation: string | null
  referred_by: string | null
  channel: string | null
  tag: string | null
  existing_id: string | null
  created_at: string
}
