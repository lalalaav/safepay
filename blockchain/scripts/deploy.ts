import { network, artifacts } from "hardhat";
import { writeFile } from "node:fs/promises";

const { ethers } = await network.connect();

const DAY = 24 * 60 * 60;
const deadline = BigInt(Math.floor(Date.now() / 1000) + 30 * DAY);

const signers = await ethers.getSigners();
const arbiter = signers[2].address; // Citra = validator

const escrow = await ethers.deployContract("SafePayEscrow", [arbiter]);
await escrow.waitForDeployment();

const escrowAddress = await escrow.getAddress();

console.log("ESCROW=" + escrowAddress);
console.log("ARBITER=" + arbiter);
console.log("DEADLINE_DEMO=" + deadline.toString());

// Tulis alamat ke deployments.json supaya Laravel otomatis memakai kontrak terbaru.
const deployment = {
  chainId: Number((await ethers.provider.getNetwork()).chainId),
  rpc: "http://127.0.0.1:8545",
  currency: "ETH",
  weiPerIdr: "1000000000000",
  escrow: escrowAddress,
  arbiter,
  faucet: signers[9].address,
};
await writeFile(new URL("../deployments.json", import.meta.url), JSON.stringify(deployment, null, 2) + "\n");

// Sinkronkan ABI terbaru langsung ke public/abi/SafePayEscrow.json supaya frontend tidak pernah beda versi.
const artifact = await artifacts.readArtifact("SafePayEscrow");
await writeFile(new URL("../../public/abi/SafePayEscrow.json", import.meta.url), JSON.stringify(artifact.abi) + "\n");

console.log("deployments.json dan public/abi/SafePayEscrow.json berhasil diperbarui");
