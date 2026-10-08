declare module "jalaali-js" {
  export function toJalaali(d: Date | { gy: number; gm: number; gd: number }): { jy: number; jm: number; jd: number };
  export function toGregorian(jy: number, jm: number, jd: number): { gy: number; gm: number; gd: number };
  export function jalaaliMonthLength(jy: number, jm: number): number;
  export function isLeapJalaaliYear(jy: number): boolean;
  const jalaali: {
    toJalaali(d: Date | { gy: number; gm: number; gd: number }): { jy: number; jm: number; jd: number };
    toGregorian(jy: number, jm: number, jd: number): { gy: number; gm: number; gd: number };
    jalaaliMonthLength(jy: number, jm: number): number;
    isLeapJalaaliYear(jy: number): boolean;
  };
  export default jalaali;
}
