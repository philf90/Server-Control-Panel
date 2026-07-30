import { mount } from "svelte";
import App from "./App.svelte";
import "./app.css";

const ziel = document.getElementById("app");
if (!ziel) {
  throw new Error("Ankerpunkt #app fehlt");
}

export default mount(App, { target: ziel });
