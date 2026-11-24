import { network } from "hardhat";
import { expect } from "chai";

const { ethers, provider } = await network.connect();

describe("SafePayEscrow", function () {
  async function deploy() {
    const [buyer, seller, arbiter, stranger] = await ethers.getSigners();
    const token = await ethers.deployContract("MockIDR");
    await token.waitForDeployment();
    const escrow = await ethers.deployContract("SafePayEscrow", [
      await token.getAddress(),
      arbiter.address,
    ]);
    await escrow.waitForDeployment();
    await (await token.mint(buyer.address, 1_000_000n)).wait();
    // Wajib: escrow tarik dana via transferFrom, jadi butuh approve dulu.
    await (await token.connect(buyer).approve(await escrow.getAddress(), 1_000_000n)).wait();
    return { buyer, seller, arbiter, stranger, token, escrow };
  }

  const soon = () => BigInt(Math.floor(Date.now() / 1000) + 3600);

  it("menahan dana, lalu melepas ke penjual setelah konfirmasi", async function () {
    const { buyer, seller, token, escrow } = await deploy();

    await (await escrow.connect(buyer).createPayment(seller.address, 200_000n, soon())).wait();

    expect(await token.balanceOf(await escrow.getAddress())).to.equal(200_000n);
    expect(await token.balanceOf(seller.address)).to.equal(0n);
    expect((await escrow.payments(0)).status).to.equal(1n); // Funded

    await (await escrow.connect(seller).markShipped(0)).wait();
    await (await escrow.connect(buyer).confirmReceived(0)).wait();

    expect(await token.balanceOf(seller.address)).to.equal(200_000n);
    expect(await token.balanceOf(await escrow.getAddress())).to.equal(0n);
    expect((await escrow.payments(0)).status).to.equal(3n); // Released
  });

  it("menolak pihak lain yang mencoba melepas dana", async function () {
    const { buyer, seller, stranger, escrow } = await deploy();
    await (await escrow.connect(buyer).createPayment(seller.address, 200_000n, soon())).wait();
    await (await escrow.connect(seller).markShipped(0)).wait();

    await expect(escrow.connect(stranger).confirmReceived(0)).to.be.revertedWith("not buyer");
  });

  it("mengembalikan dana ke pembeli saat sengketa diputus arbiter", async function () {
    const { buyer, seller, arbiter, token, escrow } = await deploy();
    await (await escrow.connect(buyer).createPayment(seller.address, 200_000n, soon())).wait();
    await (await escrow.connect(seller).markShipped(0)).wait();
    await (await escrow.connect(buyer).raiseDispute(0, "barang tidak sesuai")).wait();

    expect((await escrow.payments(0)).status).to.equal(5n); // Disputed
    await (await escrow.connect(arbiter).resolveDispute(0, false)).wait();

    expect(await token.balanceOf(buyer.address)).to.equal(1_000_000n);
    expect(await token.balanceOf(await escrow.getAddress())).to.equal(0n);
  });

  it("menerima refund hanya setelah deadline lewat", async function () {
    const { buyer, seller, token, escrow } = await deploy();
    // Setiap tx menambah 1 detik ke waktu block. Buffer 10 menit = bebas flake.
    const tight = BigInt(Math.floor(Date.now() / 1000) + 600);
    await (await escrow.connect(buyer).createPayment(seller.address, 200_000n, tight)).wait();
    await (await escrow.connect(seller).markShipped(0)).wait();

    await expect(escrow.connect(buyer).refund(0)).to.be.revertedWith("not refundable");

    await provider.send("evm_increaseTime", [1200]);
    await provider.send("evm_mine", []);
    await (await escrow.connect(buyer).refund(0)).wait();

    expect(await token.balanceOf(buyer.address)).to.equal(1_000_000n);
    expect((await escrow.payments(0)).status).to.equal(4n); // Refunded
  });
});
