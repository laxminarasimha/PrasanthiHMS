/** A small drawing beside each menu name, so a section is found by shape as well as by word. One path each, drawn as a line. */
export const NAV_ICONS: Record<string, string> = {
  'front-desk': 'M4 5h16v4H4zM6 9v10M18 9v10M9 13h6',
  doctor: 'M6 3v6a4 4 0 0 0 8 0V3M10 13v3a5 5 0 0 0 10 0v-2M20 11a1.6 1.6 0 1 0 0 .01',
  lab: 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3M8 15h8',
  pharmacy: 'M4.5 12a5.3 5.3 0 0 1 7.5-7.5l7.5 7.5a5.3 5.3 0 0 1-7.5 7.5zM8.5 8.5l7 7',
  ipd: 'M3 18V6M3 14h18v4M21 14v-2a3 3 0 0 0-3-3h-7v5M7 11.5a1.5 1.5 0 1 0 0 .01',
  patients: 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM3 20a6 6 0 0 1 12 0M16.5 4.5a3.5 3.5 0 0 1 0 6.5M18 14.5a6 6 0 0 1 3 5.5',
  reports: 'M4 20V4M4 20h16M8 16v-4M12 16V8M16 16v-6',
  workforce: 'M16 11a3 3 0 1 0 0-6M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 20a6 6 0 0 1 12 0M16 14a6 6 0 0 1 6 6',
  setup: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3.9a7 7 0 0 0-2.1-1.2L14 3h-4l-.5 2.6a7 7 0 0 0-2.1 1.2l-2.3-.9-2 3.4 2 1.5a7 7 0 0 0 0 2.4l-2 1.5 2 3.4 2.3-.9a7 7 0 0 0 2.1 1.2L10 21h4l.5-2.6a7 7 0 0 0 2.1-1.2l2.3.9 2-3.4-2-1.5c.07-.4.1-.8.1-1.2z',
  // Only on the bar at the foot of a phone's screen.
  home: 'M4 11.5 12 4l8 7.5M6 10v10h4v-6h4v6h4V10',
  schedule: 'M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1zM4 10h16M8 3v4M16 3v4M8 14h3M13 14h3M8 17h3',
  attendance: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3.5 2',
  profile: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4.5 20.5a7.5 7.5 0 0 1 15 0',
  more: 'M5 7h14M5 12h14M5 17h14',
}
