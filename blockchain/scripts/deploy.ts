import { network } from "hardhat";
import { writeFile } from "node:fs/promises";

const { ethers } = await network.connect();

const DAY = 24 * 60 * 60;
const deadline = BigInt(Math.floor(Date.now() / 1000) + 30 * DAY);

const token = await ethers.deployContract("MockIDR");
await token.waitForDeployment();

const signers = await ethers.getSigners();
const arbiter = signers[2].address; // Citra = validator

const escrow = await ethers.deployContract("SafePayEscrow", [await token.getAddress(), arbiter]);
await escrow.waitForDeployment();

// Modal simulasi 1.000.000 mIDR untuk tiap akun demo.
for (const s of signers.slice(0, 5)) {
  await (await token.mint(s.address, 1_000_000n)).wait();
}

console.log("TOKEN=" + (await token.getAddress()));
console.log("ESCROW=" + (await escrow.getAddress()));
console.log("ARBITER=" + arbiter);
console.log("DEADLINE_DEMO=" + deadline.toString());

// Tulis alamat ke file supaya Laravel otomatis ikut setelah node di-restart.
const deployment = {
  chainId: Number((await ethers.provider.getNetwork()).chainId),
  rpc: "http://127.0.0.1:8545",
  token: await token.getAddress(),
  escrow: await escrow.getAddress(),
  arbiter,
};
await writeFile(new URL("../deployments.json", import.meta.url), JSON.stringify(deployment, null, 2) + "\n");
console.log("deployments.json ditulis ulang");
