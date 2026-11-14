import * as SecureStore from 'expo-secure-store'
import { Platform } from 'react-native'

export type Saved = { kind: 'patient' | 'doctor'; token: string }

const KEY = 'hms_session'

/** Keeps the sign-in on the phone, in the system keychain; a browser preview uses its own storage. */
export async function loadSession(): Promise<Saved | null> {
  try {
    const raw = Platform.OS === 'web' ? globalThis.localStorage?.getItem(KEY) : await SecureStore.getItemAsync(KEY)
    return raw ? (JSON.parse(raw) as Saved) : null
  } catch {
    return null
  }
}

export async function saveSession(session: Saved | null): Promise<void> {
  try {
    if (Platform.OS === 'web') {
      if (session) globalThis.localStorage?.setItem(KEY, JSON.stringify(session))
      else globalThis.localStorage?.removeItem(KEY)
    } else if (session) {
      await SecureStore.setItemAsync(KEY, JSON.stringify(session))
    } else {
      await SecureStore.deleteItemAsync(KEY)
    }
  } catch {
    // The app still works for this sitting; the person just signs in again next time.
  }
}
