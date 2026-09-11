/**
 * The account menu's phone dropdown: choosing a screen opens it.
 *
 * Every other item is a plain link, so this is all the menu needs.
 */
export function bootAccountMenu(): void {
  document.addEventListener('change', (event) => {
    const select = (event.target as Element | null)?.closest?.<HTMLSelectElement>('.galaxie-account-menu-select')

    if (select?.value) {
      window.location.assign(select.value)
    }
  })
}
